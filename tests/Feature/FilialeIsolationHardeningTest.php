<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\QrMode;
use App\Enums\UserRole;
use App\Http\Middleware\ApplyFilialeScope;
use App\Models\Event;
use App\Models\EventType;
use App\Models\Filiale;
use App\Models\User;
use App\Services\Attendance\AttendanceInput;
use App\Services\AttendanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Audit ADVERSARIAL du cloisonnement multi-filiale — durcissement (2026-07).
 *
 * Complète {@see FilialeIsolationScopingTest} en verrouillant les surfaces
 * admin dont l'accès croisé A→B n'était PAS explicitement couvert par un test de
 * régression, et les deux modes du SuperAdmin (« Toutes les filiales » vs contexte
 * verrouillé). Chaque test est écrit pour ÉCHOUER si la garantie de cloisonnement
 * est un jour retirée (binding scopé, policy, middleware de rôle).
 *
 * On vérifie systématiquement le 404 (route-model binding filtré par le global
 * scope AVANT l'entrée dans le contrôleur) plutôt qu'une réponse vide, ET on
 * fournit la contre-preuve SuperAdmin quand c'est pertinent (sinon un 404
 * universel — route cassée — passerait pour de l'isolation).
 */
class FilialeIsolationHardeningTest extends TestCase
{
    use RefreshDatabase;

    private Filiale $filialeA;

    private Filiale $filialeB;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->filialeA = Filiale::factory()->create(['name' => 'ACS Immobilier']);
        $this->filialeB = Filiale::factory()->create(['name' => 'ACS Energie']);
    }

    private function typeFor(Filiale $filiale, string $name): EventType
    {
        return EventType::create([
            'filiale_id' => $filiale->id,
            'name' => $name,
            'color' => '#7c3aed',
            'position' => 0,
        ]);
    }

    private function eventIn(Filiale $filiale, string $title): Event
    {
        return Event::create([
            'filiale_id' => $filiale->id,
            'title' => $title,
            'event_type_id' => $this->typeFor($filiale, $title.' type')->id,
            'starts_at' => Carbon::now()->subHour(),
            'ends_at' => Carbon::now()->addHour(),
            'qr_mode' => QrMode::Statique->value,
            'qr_secret' => Str::random(32),
            'public_slug' => Str::slug($title).'-'.Str::random(6),
        ]);
    }

    private function attend(Event $event, string $email): void
    {
        app(AttendanceService::class)->register($event, new AttendanceInput(
            email: $email, lastName: 'Koné', firstName: 'Awa',
            phone: '0', company: 'ACS', direction: 'SI', position: 'Analyste',
        ));
    }

    // =====================================================================
    // 1. Portfolio SHOW ({event}) — l'index était scopé, la fiche non testée
    // =====================================================================

    public function test_portfolio_show_dune_autre_filiale_est_introuvable(): void
    {
        $eventB = $this->eventIn($this->filialeB, 'Activite Beta');
        $eventB->report()->create(['body' => 'Compte-rendu confidentiel B']);

        $orgaA = User::factory()->forFiliale($this->filialeA)->create();
        $adminA = User::factory()->filialeAdmin()->forFiliale($this->filialeA)->create();

        // Binding {event} filtré par le global scope → 404 avant le contrôleur.
        $this->actingAs($orgaA)->get(route('admin.portfolio.show', $eventB))->assertNotFound();
        $this->actingAs($adminA)->get(route('admin.portfolio.show', $eventB))->assertNotFound();

        // Contre-preuve : le SuperAdmin transversal accède bien à la fiche.
        $super = User::factory()->superAdmin()->create();
        $this->actingAs($super)->get(route('admin.portfolio.show', $eventB))->assertOk();
    }

    // =====================================================================
    // 2. Écritures d'événement NON couvertes : update, uncancel, reschedule,
    //    qr.print, departure/undo-departure — accès croisé A→B
    // =====================================================================

    public function test_ecritures_evenement_non_couvertes_dune_autre_filiale_sont_introuvables(): void
    {
        $eventB = $this->eventIn($this->filialeB, 'Evenement Beta');
        $this->attend($eventB, 'awa@acs.ci');
        $attendanceB = $eventB->attendances()->firstOrFail();

        $orgaA = User::factory()->forFiliale($this->filialeA)->create();
        $this->actingAs($orgaA);

        // Le binding scopé 404 AVANT toute validation : payload minimal suffit.
        $this->patchJson(route('admin.events.update', $eventB), ['title' => 'Piraté'])->assertNotFound();
        $this->postJson(route('admin.events.uncancel', $eventB))->assertNotFound();
        $this->postJson(route('admin.events.reschedule', $eventB), [])->assertNotFound();

        // Surface QR d'impression (fuite potentielle d'un secret/token statique).
        $this->get(route('admin.events.qr.print', $eventB))->assertNotFound();

        // Gestion des départs (POST imbriqués {event}/{attendance}) : {event} 404.
        $this->postJson(route('admin.events.attendances.departure', [$eventB, $attendanceB]))->assertNotFound();
        $this->postJson(route('admin.events.attendances.undo-departure', [$eventB, $attendanceB]))->assertNotFound();

        // Rien n'a bougé côté données.
        $this->assertSame('Evenement Beta', $eventB->refresh()->title);
    }

    // =====================================================================
    // 3. Médias de compte-rendu : SUPPRESSION et AJOUT croisés (IDOR d'écriture)
    //    Seul `.show` était testé ; destroy = suppression irréversible.
    // =====================================================================

    public function test_suppression_et_ajout_de_medias_dune_autre_filiale_sont_introuvables(): void
    {
        $eventB = $this->eventIn($this->filialeB, 'Evenement Beta');

        $docPath = 'reports/'.$eventB->id.'/documents/secret.pdf';
        Storage::disk('local')->put($docPath, 'CONFIDENTIEL-B');
        $documentB = $eventB->documents()->create([
            'original_name' => 'secret.pdf', 'path' => $docPath,
            'mime' => 'application/pdf', 'size' => 13,
        ]);

        $photoPath = 'reports/'.$eventB->id.'/photos/secret.jpg';
        Storage::disk('local')->put($photoPath, 'IMAGE-B');
        $photoB = $eventB->photos()->create(['path' => $photoPath, 'position' => 1]);

        $orgaA = User::factory()->forFiliale($this->filialeA)->create();
        $this->actingAs($orgaA);

        // Suppression croisée → 404 (binding {event} scopé), média toujours présent.
        $this->deleteJson(route('admin.events.report.documents.destroy', [$eventB, $documentB]))->assertNotFound();
        $this->deleteJson(route('admin.events.report.photos.destroy', [$eventB, $photoB]))->assertNotFound();

        // Ajout croisé (upload) → 404 également.
        $this->postJson(route('admin.events.report.documents.store', $eventB), [])->assertNotFound();
        $this->postJson(route('admin.events.report.photos.store', $eventB), [])->assertNotFound();

        // Preuve d'intégrité : ni la BDD ni le disque n'ont été touchés.
        $this->assertDatabaseHas('report_documents', ['id' => $documentB->id]);
        $this->assertDatabaseHas('report_photos', ['id' => $photoB->id]);
        Storage::disk('local')->assertExists($docPath);
        Storage::disk('local')->assertExists($photoPath);
    }

    // =====================================================================
    // 4. Sélecteur de contexte filiale : réservé au SuperAdmin (role middleware)
    // =====================================================================

    public function test_selecteur_de_contexte_filiale_refuse_les_non_super_admin(): void
    {
        $orgaA = User::factory()->forFiliale($this->filialeA)->create();
        $adminA = User::factory()->filialeAdmin()->forFiliale($this->filialeA)->create();

        // Le middleware role:super_admin doit rejeter (403) : un AdminFiliale/
        // Organisateur ne peut jamais élargir son périmètre en changeant de contexte.
        $this->actingAs($orgaA)
            ->post(route('admin.filiale-context.update'), ['filiale_id' => $this->filialeB->id])
            ->assertForbidden();

        $this->actingAs($adminA)
            ->post(route('admin.filiale-context.update'), ['filiale_id' => $this->filialeB->id])
            ->assertForbidden();
    }

    // =====================================================================
    // 5. SuperAdmin VERROUILLÉ sur une filiale ≠ SuperAdmin « Toutes »
    //    Les deux modes doivent produire des périmètres distincts, y compris
    //    au route-model binding et sur les actions sensibles.
    // =====================================================================

    public function test_super_admin_verrouille_sur_une_filiale_ne_resout_pas_les_ressources_hors_contexte(): void
    {
        $eventA = $this->eventIn($this->filialeA, 'Evenement Alpha');
        $typeA = $this->typeFor($this->filialeA, 'Type Alpha');
        $super = User::factory()->superAdmin()->create();

        // Contexte session VERROUILLÉ sur la filiale B : tout ce qui est en A devient
        // introuvable (le scope filtre même pour le SuperAdmin quand il est scopé).
        $ctx = [ApplyFilialeScope::CONTEXT_SESSION_KEY => $this->filialeB->id];

        $this->actingAs($super)->withSession($ctx)
            ->get(route('admin.events.show', $eventA))->assertNotFound();

        $this->actingAs($super)->withSession($ctx)
            ->patchJson(route('admin.settings.types.update', $typeA), ['name' => 'x', 'color' => '#000000'])
            ->assertNotFound();

        // Action sensible (transfert) : le binding {event} 404 avant la policy →
        // un SuperAdmin verrouillé sur B ne peut même pas cibler un événement de A.
        $this->actingAs($super)->withSession($ctx)
            ->post(route('admin.events.transfer', $eventA), [])->assertNotFound();
    }

    public function test_super_admin_en_toutes_filiales_resout_les_ressources_de_chaque_filiale(): void
    {
        // Contre-preuve du test précédent : sans contexte (mode « Toutes »), le même
        // SuperAdmin atteint les ressources de A comme de B.
        $eventA = $this->eventIn($this->filialeA, 'Evenement Alpha');
        $eventB = $this->eventIn($this->filialeB, 'Evenement Beta');
        $super = User::factory()->superAdmin()->create();

        $this->actingAs($super)->get(route('admin.events.show', $eventA))->assertOk();
        $this->actingAs($super)->get(route('admin.events.show', $eventB))->assertOk();
    }

    public function test_super_admin_verrouille_sur_la_filiale_source_transfere_toujours(): void
    {
        // Mode verrouillé mais COHÉRENT : le SuperAdmin scopé sur la filiale source
        // atteint bien l'événement et l'action sensible reste fonctionnelle (on ne
        // veut pas d'un faux positif où « tout 404 »).
        $eventA = $this->eventIn($this->filialeA, 'Evenement Alpha');
        $targetType = $this->typeFor($this->filialeB, 'Type Cible B');
        $super = User::factory()->superAdmin()->create();

        $this->actingAs($super)
            ->withSession([ApplyFilialeScope::CONTEXT_SESSION_KEY => $this->filialeA->id])
            ->post(route('admin.events.transfer', $eventA), [
                'filiale_id' => $this->filialeB->id,
                'event_type_id' => $targetType->id,
                'scope' => 'seance',
            ])
            ->assertRedirect(route('admin.events.index'));

        $this->assertSame($this->filialeB->id, $eventA->refresh()->filiale_id);
    }

    // =====================================================================
    // 6. RME-7 (fail-closed) renforcé sur les ÉCRITURES d'événement
    //    L'existant couvre index/show (lecture) et la gestion de comptes ;
    //    ici on prouve le fail-closed sur les surfaces d'écriture.
    // =====================================================================

    public function test_fail_closed_rme7_sur_les_ecritures_evenement(): void
    {
        $eventA = $this->eventIn($this->filialeA, 'Evenement Alpha');

        // Organisateur incohérent : filiale_id NULL → denyAll (whereRaw 1=0). Il ne
        // doit RIEN atteindre, jamais un repli « tout voir ».
        $broken = User::factory()->create([
            'role' => UserRole::Organisateur, 'filiale_id' => null,
        ]);
        $this->actingAs($broken);

        $this->patchJson(route('admin.events.update', $eventA), ['title' => 'Piraté'])->assertNotFound();
        $this->postJson(route('admin.events.attendances.manual', $eventA), ['last_name' => 'X'])->assertNotFound();
        $this->get(route('admin.portfolio.show', $eventA))->assertNotFound();

        $this->assertSame('Evenement Alpha', $eventA->refresh()->title);
    }

    // =====================================================================
    // 7. Surface publique /e/{slug} : jamais scopée, aucune dépendance à un
    //    contexte de session filiale (RME-2). Même avec un contexte admin en
    //    session, la page publique reste accessible pour toute filiale.
    // =====================================================================

    public function test_page_publique_reste_accessible_meme_avec_un_contexte_filiale_en_session(): void
    {
        $eventB = $this->eventIn($this->filialeB, 'Public Beta');

        // Session portant un contexte filiale (A) : la page publique de B ne doit
        // pas en dépendre (elle n'est pas dans le groupe `filiale.scope`).
        $this->withSession([ApplyFilialeScope::CONTEXT_SESSION_KEY => $this->filialeA->id])
            ->get(route('public.attendance.show', $eventB->public_slug))
            ->assertOk();

        // Et la route publique n'expose pas le middleware de scope filiale.
        $middlewares = collect(app('router')->getRoutes()->getByName('public.attendance.show')->gatherMiddleware());
        $this->assertFalse(
            $middlewares->contains('filiale.scope'),
            'La page publique ne doit jamais porter le middleware filiale.scope (RME-2).',
        );
    }
}
