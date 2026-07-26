<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\QrMode;
use App\Models\Event;
use App\Models\EventType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class EventReportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private EventType $type;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        // Le disque public est faké aussi pour vérifier qu'aucun média n'y atterrit.
        Storage::fake('public');
        $this->user = User::factory()->create();
        $this->type = EventType::create(['name' => 'Atelier', 'color' => '#7c3aed', 'position' => 0]);
    }

    private function event(bool $started = true): Event
    {
        return Event::create([
            'title' => 'Atelier', 'event_type_id' => $this->type->id,
            'starts_at' => $started ? Carbon::now()->subHour() : Carbon::now()->addDay(),
            'ends_at' => $started ? Carbon::now()->addHour() : Carbon::now()->addDay()->addHour(),
            'qr_mode' => QrMode::Statique->value, 'qr_secret' => Str::random(32),
            'public_slug' => 'atelier-'.Str::random(5),
        ]);
    }

    public function test_upload_document(): void
    {
        $event = $this->event();

        $response = $this->actingAs($this->user)->postJson(route('admin.events.report.documents.store', $event), [
            'files' => [UploadedFile::fake()->create('bilan.pdf', 200, 'application/pdf')],
        ])->assertStatus(201);

        $this->assertDatabaseHas('report_documents', ['event_id' => $event->id, 'original_name' => 'bilan.pdf']);
        $path = $event->documents()->first()->path;
        Storage::disk('local')->assertExists($path);
        // Défense en profondeur : le média ne doit JAMAIS atterrir sur le disque public.
        Storage::disk('public')->assertMissing($path);
        $this->assertNotNull($response->json('documents.0.delete_url'));
    }

    public function test_upload_document_refuse_mauvais_type(): void
    {
        $event = $this->event();

        $this->actingAs($this->user)->postJson(route('admin.events.report.documents.store', $event), [
            'files' => [UploadedFile::fake()->create('malware.exe', 10)],
        ])->assertStatus(422);
    }

    public function test_upload_de_plusieurs_photos_en_une_requete(): void
    {
        $event = $this->event();

        $resp = $this->actingAs($this->user)->postJson(route('admin.events.report.photos.store', $event), [
            'files' => [
                UploadedFile::fake()->image('a.jpg', 100, 100),
                UploadedFile::fake()->image('b.jpg', 100, 100),
                UploadedFile::fake()->image('c.jpg', 100, 100),
            ],
        ])->assertStatus(201);

        $resp->assertJsonCount(3, 'photos');
        $this->assertSame(3, $event->photos()->count());
        // Positions séquentielles et sans collision : ordre stable de la galerie.
        $this->assertSame([1, 2, 3], $event->photos()->orderBy('position')->pluck('position')->all());
    }

    public function test_uploads_photo_successifs_incrementent_la_position(): void
    {
        $event = $this->event();
        // Une photo préexistante (position 1) : le prochain lot repart de 2.
        $event->photos()->create(['path' => 'reports/'.$event->id.'/photos/seed.jpg', 'position' => 1]);

        $this->actingAs($this->user)->postJson(route('admin.events.report.photos.store', $event), [
            'files' => [
                UploadedFile::fake()->image('x.jpg', 100, 100),
                UploadedFile::fake()->image('y.jpg', 100, 100),
            ],
        ])->assertStatus(201);

        $this->assertSame([1, 2, 3], $event->photos()->orderBy('position')->pluck('position')->all());
    }

    public function test_upload_document_conserve_un_nom_unicode(): void
    {
        $event = $this->event();
        // Nom hostile : accents, tiret cadratin, parenthèses, apostrophe.
        $name = "Compte-rendu réunion — été 2026 (café, l'équipe).pdf";

        $this->actingAs($this->user)->postJson(route('admin.events.report.documents.store', $event), [
            'files' => [UploadedFile::fake()->create($name, 50, 'application/pdf')],
        ])->assertStatus(201);

        $doc = $event->documents()->firstOrFail();
        // Le nom d'origine (unicode) est conservé tel quel en base.
        $this->assertSame($name, $doc->original_name);

        // Et le fichier reste servi correctement depuis le disque privé.
        $this->actingAs($this->user)
            ->get(route('admin.events.report.documents.show', [$event, $doc]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_upload_photo_avec_nom_unicode(): void
    {
        $event = $this->event();

        $this->actingAs($this->user)->postJson(route('admin.events.report.photos.store', $event), [
            'files' => [UploadedFile::fake()->image('séminaire équipe — été.jpg', 200, 150)],
        ])->assertStatus(201);

        $photo = $event->photos()->firstOrFail();
        Storage::disk('local')->assertExists($photo->path);
    }

    /**
     * Non-régression : la saisie manuelle du compte-rendu (ReportController::saveText)
     * a été retirée. Aucune route nommée ne doit subsister, et l'ancienne URL doit
     * répondre 404 (route inexistante) — jamais une 500.
     */
    public function test_la_route_denregistrement_du_texte_du_compte_rendu_nexiste_plus(): void
    {
        $this->assertFalse(Route::has('admin.events.report.save'));

        $event = $this->event();
        $this->actingAs($this->user)
            ->post("/admin/events/{$event->id}/report", ['body' => 'Texte manuel supprimé'])
            ->assertNotFound();
    }

    public function test_upload_et_suppression_photo(): void
    {
        $event = $this->event();

        $resp = $this->actingAs($this->user)->postJson(route('admin.events.report.photos.store', $event), [
            'files' => [UploadedFile::fake()->image('activite.jpg', 800, 600)],
        ])->assertStatus(201);

        $photo = $event->photos()->firstOrFail();
        Storage::disk('local')->assertExists($photo->path);

        $this->actingAs($this->user)
            ->deleteJson(route('admin.events.report.photos.destroy', [$event, $photo]))
            ->assertOk();
        $this->assertDatabaseMissing('report_photos', ['id' => $photo->id]);
        Storage::disk('local')->assertMissing($photo->path);
    }

    public function test_url_du_document_pointe_vers_la_route_authentifiee_pas_le_disque_public(): void
    {
        $event = $this->event();
        $this->actingAs($this->user)->postJson(route('admin.events.report.documents.store', $event), [
            'files' => [UploadedFile::fake()->create('bilan.pdf', 50, 'application/pdf')],
        ])->assertStatus(201);

        $doc = $event->documents()->firstOrFail();

        $this->assertSame(
            route('admin.events.report.documents.show', [$event->id, $doc->id]),
            $doc->url(),
        );
        // Aucune trace d'URL /storage/ publique.
        $this->assertStringNotContainsString('/storage/', $doc->url());
    }

    public function test_document_servi_depuis_le_disque_prive_pour_un_utilisateur_authentifie(): void
    {
        $event = $this->event();
        $this->actingAs($this->user)->postJson(route('admin.events.report.documents.store', $event), [
            'files' => [UploadedFile::fake()->create('bilan.pdf', 50, 'application/pdf')],
        ])->assertStatus(201);

        $doc = $event->documents()->firstOrFail();

        $this->actingAs($this->user)
            ->get(route('admin.events.report.documents.show', [$event, $doc]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_photo_servie_depuis_le_disque_prive_pour_un_utilisateur_authentifie(): void
    {
        $event = $this->event();
        $this->actingAs($this->user)->postJson(route('admin.events.report.photos.store', $event), [
            'files' => [UploadedFile::fake()->image('activite.jpg', 400, 300)],
        ])->assertStatus(201);

        $photo = $event->photos()->firstOrFail();

        $this->actingAs($this->user)
            ->get(route('admin.events.report.photos.show', [$event, $photo]))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_document_inaccessible_sans_authentification(): void
    {
        // Semé directement (sans actingAs, qui persisterait l'auth pour tout le test).
        $event = $this->event();
        $path = 'reports/'.$event->id.'/documents/secret.pdf';
        Storage::disk('local')->put($path, 'CONFIDENTIEL');
        $doc = $event->documents()->create([
            'original_name' => 'secret.pdf', 'path' => $path, 'mime' => 'application/pdf', 'size' => 12,
        ]);

        // Non connecté → redirigé vers la connexion (jamais servi).
        $this->get(route('admin.events.report.documents.show', [$event, $doc]))
            ->assertRedirect(route('login'));
    }

    public function test_photo_inaccessible_sans_authentification(): void
    {
        $event = $this->event();
        $path = 'reports/'.$event->id.'/photos/secret.jpg';
        Storage::disk('local')->put($path, 'IMAGE');
        $photo = $event->photos()->create(['path' => $path, 'position' => 1]);

        $this->get(route('admin.events.report.photos.show', [$event, $photo]))
            ->assertRedirect(route('login'));
    }

    public function test_media_show_scope_a_son_evenement(): void
    {
        $event = $this->event();
        $other = $this->event();
        $this->actingAs($this->user)->postJson(route('admin.events.report.documents.store', $event), [
            'files' => [UploadedFile::fake()->create('doc.pdf', 50, 'application/pdf')],
        ]);
        $doc = $event->documents()->firstOrFail();

        // Servir via un AUTRE événement → 404 (scopeBindings).
        $this->actingAs($this->user)
            ->get(route('admin.events.report.documents.show', [$other, $doc->id]))
            ->assertNotFound();
    }

    public function test_media_scope_a_son_evenement(): void
    {
        $event = $this->event();
        $other = $this->event();
        $resp = $this->actingAs($this->user)->postJson(route('admin.events.report.documents.store', $event), [
            'files' => [UploadedFile::fake()->create('doc.pdf', 50, 'application/pdf')],
        ]);
        $docId = $resp->json('documents.0.id');

        // Suppression via un autre événement → 404 (scopeBindings).
        $this->actingAs($this->user)
            ->deleteJson(route('admin.events.report.documents.destroy', [$other, $docId]))
            ->assertNotFound();
    }
}
