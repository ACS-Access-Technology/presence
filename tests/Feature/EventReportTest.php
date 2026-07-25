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

    public function test_enregistre_le_texte(): void
    {
        $event = $this->event();

        $this->actingAs($this->user)->postJson(route('admin.events.report.save', $event), [
            'body' => "## Bilan\n\n- Objectif atteint",
        ])->assertOk();

        $this->assertDatabaseHas('event_reports', ['event_id' => $event->id]);
        $this->assertStringContainsString('Bilan', $event->report()->first()->body);
    }

    public function test_texte_bloque_avant_le_debut(): void
    {
        $event = $this->event(started: false);

        $this->actingAs($this->user)->postJson(route('admin.events.report.save', $event), ['body' => 'x'])
            ->assertStatus(422);
        $this->assertDatabaseCount('event_reports', 0);
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
