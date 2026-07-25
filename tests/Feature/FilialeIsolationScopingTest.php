<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\QrMode;
use App\Enums\UserRole;
use App\Models\Event;
use App\Models\EventType;
use App\Models\Filiale;
use App\Models\Person;
use App\Models\User;
use App\Services\Attendance\AttendanceInput;
use App\Services\AttendanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Lot G — T-ME-22 : suite d'isolation inter-filiales ADVERSARIALE.
 *
 * Objectif : tenter activement l'accès croisé A→B sur les surfaces NON couvertes
 * (ou couvertes partiellement) par les tests existants — CHAQUE méthode d'export,
 * statistiques, portfolio, annuaire participants, dashboard — et sur les
 * combinaisons de gestion de comptes non testées (Organisateur bloqué avant la
 * policy, AdminFiliale fail-closed, gestion d'un pair AdminFiliale / d'un
 * SuperAdmin). On vérifie EMPIRIQUEMENT le 404 (route-model binding scopé), pas un
 * export vide ; et on prouve que le SuperAdmin, lui, accède bien (sinon un 404
 * universel — route cassée — passerait pour de l'isolation).
 */
class FilialeIsolationScopingTest extends TestCase
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

    private function attend(Event $event, string $email, string $company): void
    {
        app(AttendanceService::class)->register($event, new AttendanceInput(
            email: $email, lastName: 'Koné', firstName: 'Awa',
            phone: '0', company: $company, direction: 'SI', position: 'Analyste',
        ));
    }

    // =====================================================================
    // Exports : CHAQUE méthode doit 404 en accès croisé (pas un export vide)
    // =====================================================================

    /** @return array<int, array{0: string, 1: array<string, string>}> */
    private function exportRouteNames(): array
    {
        return [
            ['admin.events.attendances.export', []],
            ['admin.events.attendances.export.xlsx', []],
            ['admin.events.attendances.export.pdf', []],
            ['admin.events.attendances.badges', []],
            ['admin.events.attendances.feed', []],
        ];
    }

    public function test_organisateur_ne_peut_exporter_aucun_format_dun_evenement_dune_autre_filiale(): void
    {
        $eventB = $this->eventIn($this->filialeB, 'Evenement Beta');
        $orgaA = User::factory()->forFiliale($this->filialeA)->create();

        foreach ($this->exportRouteNames() as [$name]) {
            $this->actingAs($orgaA)
                ->get(route($name, $eventB))
                ->assertNotFound(); // 404 via global scope, pas un 200 vide.
        }
    }

    public function test_admin_filiale_ne_peut_exporter_aucun_format_dun_evenement_dune_autre_filiale(): void
    {
        $eventB = $this->eventIn($this->filialeB, 'Evenement Beta');
        $adminA = User::factory()->filialeAdmin()->forFiliale($this->filialeA)->create();

        foreach ($this->exportRouteNames() as [$name]) {
            $this->actingAs($adminA)
                ->get(route($name, $eventB))
                ->assertNotFound();
        }
    }

    public function test_super_admin_peut_exporter_chaque_format_de_toute_filiale(): void
    {
        // Contre-preuve : si le SuperAdmin recevait aussi un 404, l'isolation
        // ci-dessus serait un faux positif (route cassée). Il DOIT accéder.
        $eventB = $this->eventIn($this->filialeB, 'Evenement Beta');
        $this->attend($eventB, 'awa@acs.ci', 'BetaCorp');
        $super = User::factory()->superAdmin()->create();

        foreach ($this->exportRouteNames() as [$name]) {
            $this->actingAs($super)
                ->get(route($name, $eventB))
                ->assertOk();
        }
    }

    public function test_ecritures_et_qr_sur_un_evenement_dune_autre_filiale_sont_introuvables(): void
    {
        $eventB = $this->eventIn($this->filialeB, 'Evenement Beta');
        $orgaA = User::factory()->forFiliale($this->filialeA)->create();

        // Saisie manuelle (POST) : le binding scopé 404 AVANT toute validation.
        $this->actingAs($orgaA)
            ->postJson(route('admin.events.attendances.manual', $eventB), ['last_name' => 'X'])
            ->assertNotFound();

        // Cycle de vie + surfaces QR (fuite potentielle d'un secret/token de projection).
        $this->actingAs($orgaA)->postJson(route('admin.events.cancel', $eventB))->assertNotFound();
        $this->actingAs($orgaA)->patchJson(route('admin.events.qr-mode', $eventB), ['qr_mode' => 'tournant'])->assertNotFound();
        $this->actingAs($orgaA)->get(route('admin.events.projection', $eventB))->assertNotFound();
        $this->actingAs($orgaA)->get(route('admin.events.qr.current', $eventB))->assertNotFound();
    }

    public function test_la_signature_dune_presence_dune_autre_filiale_est_introuvable(): void
    {
        $eventB = $this->eventIn($this->filialeB, 'Evenement Beta');
        $this->attend($eventB, 'awa@acs.ci', 'BetaCorp');
        $attendanceB = $eventB->attendances()->firstOrFail();
        $orgaA = User::factory()->forFiliale($this->filialeA)->create();

        $this->actingAs($orgaA)
            ->get(route('admin.events.attendances.signature', [$eventB, $attendanceB]))
            ->assertNotFound();
    }

    // =====================================================================
    // Statistiques (Event::query + Attendance::whereHas) : héritage du scope
    // =====================================================================

    public function test_les_statistiques_sont_scopees_par_filiale(): void
    {
        $eventA = $this->eventIn($this->filialeA, 'Reunion Alpha');
        $eventB = $this->eventIn($this->filialeB, 'Reunion Beta');
        $this->attend($eventA, 'a@acs.ci', 'AlphaCorpTemoin');
        $this->attend($eventB, 'b@acs.ci', 'BetaCorpTemoin');

        $orgaA = User::factory()->forFiliale($this->filialeA)->create();

        $this->actingAs($orgaA)->get(route('admin.statistics'))
            ->assertOk()
            ->assertSee('AlphaCorpTemoin')
            ->assertDontSee('BetaCorpTemoin'); // La société d'une autre filiale ne fuit pas.
    }

    public function test_les_statistiques_super_admin_agregent_toutes_les_filiales(): void
    {
        $eventA = $this->eventIn($this->filialeA, 'Reunion Alpha');
        $eventB = $this->eventIn($this->filialeB, 'Reunion Beta');
        $this->attend($eventA, 'a@acs.ci', 'AlphaCorpTemoin');
        $this->attend($eventB, 'b@acs.ci', 'BetaCorpTemoin');

        $super = User::factory()->superAdmin()->create();

        $this->actingAs($super)->get(route('admin.statistics'))
            ->assertOk()
            ->assertSee('AlphaCorpTemoin')
            ->assertSee('BetaCorpTemoin')
            // Ventilation par filiale (Q-ME-6) : les deux filiales nommées.
            ->assertSee('ACS Immobilier')
            ->assertSee('ACS Energie');
    }

    // =====================================================================
    // Portfolio (Event::query directe) : héritage du scope
    // =====================================================================

    private function documentedEvent(Filiale $filiale, string $title): Event
    {
        $event = $this->eventIn($filiale, $title);
        $event->report()->create(['body' => 'Compte-rendu de '.$title]);

        return $event;
    }

    public function test_le_portfolio_est_scope_par_filiale(): void
    {
        $this->documentedEvent($this->filialeA, 'PortfolioAlpha');
        $this->documentedEvent($this->filialeB, 'PortfolioBeta');

        $orgaA = User::factory()->forFiliale($this->filialeA)->create();

        $this->actingAs($orgaA)->get(route('admin.portfolio'))
            ->assertOk()
            ->assertSee('PortfolioAlpha')
            ->assertDontSee('PortfolioBeta');
    }

    public function test_le_portfolio_super_admin_voit_toutes_les_filiales(): void
    {
        $this->documentedEvent($this->filialeA, 'PortfolioAlpha');
        $this->documentedEvent($this->filialeB, 'PortfolioBeta');

        $super = User::factory()->superAdmin()->create();

        $this->actingAs($super)->get(route('admin.portfolio'))
            ->assertOk()
            ->assertSee('PortfolioAlpha')
            ->assertSee('PortfolioBeta');
    }

    public function test_les_medias_de_compte_rendu_dune_autre_filiale_sont_introuvables(): void
    {
        $eventB = $this->eventIn($this->filialeB, 'Evenement Beta');

        $docPath = 'reports/'.$eventB->id.'/documents/secret.pdf';
        Storage::disk('local')->put($docPath, 'CONFIDENTIEL-FILIALE-B');
        $documentB = $eventB->documents()->create([
            'original_name' => 'secret.pdf', 'path' => $docPath,
            'mime' => 'application/pdf', 'size' => 20,
        ]);

        $photoPath = 'reports/'.$eventB->id.'/photos/secret.jpg';
        Storage::disk('local')->put($photoPath, 'IMAGE-FILIALE-B');
        $photoB = $eventB->photos()->create(['path' => $photoPath, 'position' => 1]);

        $orgaA = User::factory()->forFiliale($this->filialeA)->create();

        // Accès croisé A→B via la route authentifiée : 404 (binding scopé filiale).
        $this->actingAs($orgaA)
            ->get(route('admin.events.report.documents.show', [$eventB, $documentB]))
            ->assertNotFound();

        $this->actingAs($orgaA)
            ->get(route('admin.events.report.photos.show', [$eventB, $photoB]))
            ->assertNotFound();

        // Le SuperAdmin, lui, accède bien (sinon un 404 universel — route cassée —
        // passerait pour de l'isolation).
        $super = User::factory()->superAdmin()->create();
        $this->actingAs($super)
            ->get(route('admin.events.report.documents.show', [$eventB, $documentB]))
            ->assertOk();
        $this->actingAs($super)
            ->get(route('admin.events.report.photos.show', [$eventB, $photoB]))
            ->assertOk();
    }

    // =====================================================================
    // Annuaire participants : Person UNIFIÉ mais historique SCOPÉ (Q-ME-4)
    // =====================================================================

    public function test_une_personne_ayant_emarge_dans_deux_filiales_ne_montre_que_lhistorique_de_la_filiale_courante(): void
    {
        // Même email → un seul Person (référentiel holding, D-ME-1), deux présences
        // sur deux filiales différentes. C'est le cœur du risque RME-8.
        $eventA = $this->eventIn($this->filialeA, 'Sommet Alpha');
        $eventB = $this->eventIn($this->filialeB, 'Sommet Beta');
        $this->attend($eventA, 'partout@acs.ci', 'ACS');
        $this->attend($eventB, 'partout@acs.ci', 'ACS');

        $person = Person::where('email', 'partout@acs.ci')->firstOrFail();
        $orgaA = User::factory()->forFiliale($this->filialeA)->create();

        $this->actingAs($orgaA)->get(route('admin.participants.show', $person))
            ->assertOk()
            ->assertSee('Sommet Alpha')
            ->assertDontSee('Sommet Beta'); // L'historique de l'autre filiale reste invisible.
    }

    public function test_une_personne_nayant_emarge_que_dans_une_autre_filiale_est_introuvable(): void
    {
        $eventB = $this->eventIn($this->filialeB, 'Sommet Beta');
        $this->attend($eventB, 'seulement-b@acs.ci', 'ACS Energie SA');

        $person = Person::where('email', 'seulement-b@acs.ci')->firstOrFail();
        $orgaA = User::factory()->forFiliale($this->filialeA)->create();

        // Person n'a pas de global scope : l'URL directe résout la personne, mais
        // aucune présence n'est dans le périmètre → 404, pas de fuite de PII.
        $this->actingAs($orgaA)->get(route('admin.participants.show', $person))
            ->assertNotFound();

        // …et elle n'apparaît pas non plus dans l'annuaire de la filiale A.
        $this->actingAs($orgaA)->get(route('admin.participants.index'))
            ->assertOk()
            ->assertDontSee('ACS Energie SA');
    }

    public function test_le_super_admin_voit_lhistorique_complet_dune_personne(): void
    {
        $eventA = $this->eventIn($this->filialeA, 'Sommet Alpha');
        $eventB = $this->eventIn($this->filialeB, 'Sommet Beta');
        $this->attend($eventA, 'partout@acs.ci', 'ACS');
        $this->attend($eventB, 'partout@acs.ci', 'ACS');

        $person = Person::where('email', 'partout@acs.ci')->firstOrFail();
        $super = User::factory()->superAdmin()->create();

        $this->actingAs($super)->get(route('admin.participants.show', $person))
            ->assertOk()
            ->assertSee('Sommet Alpha')
            ->assertSee('Sommet Beta');
    }

    // =====================================================================
    // Dashboard = redirect vers la liste scopée
    // =====================================================================

    public function test_le_dashboard_redirige_vers_la_liste_devenements(): void
    {
        $orgaA = User::factory()->forFiliale($this->filialeA)->create();

        $this->actingAs($orgaA)->get(route('admin.dashboard'))
            ->assertRedirect(route('admin.events.index'));
    }

    // =====================================================================
    // Gestion des comptes : ordre middleware/policy + fail-closed + escalade
    // =====================================================================

    public function test_un_organisateur_est_bloque_par_le_middleware_de_role_avant_toute_policy(): void
    {
        // Un Organisateur n'a AUCUN accès aux routes settings.accounts.* : le
        // middleware role:admin_filiale,super_admin doit rejeter (403) AVANT même
        // que l'UserPolicy ne soit consultée.
        $orgaA = User::factory()->forFiliale($this->filialeA)->create();
        $cibleA = User::factory()->forFiliale($this->filialeA)->create();

        $this->actingAs($orgaA)->postJson(route('admin.settings.accounts.store'), [
            'name' => 'X', 'email' => 'x@acs.ci', 'role' => 'organisateur',
        ])->assertForbidden();

        $this->actingAs($orgaA)->patchJson(route('admin.settings.accounts.update', $cibleA), [
            'role' => 'organisateur', 'is_active' => false,
        ])->assertForbidden();

        $this->actingAs($orgaA)->deleteJson(route('admin.settings.accounts.destroy', $cibleA))->assertForbidden();
        $this->actingAs($orgaA)->postJson(route('admin.settings.accounts.reset', $cibleA))->assertForbidden();

        // Et la surface Paramètres elle-même reste fermée.
        $this->actingAs($orgaA)->get(route('admin.settings.index'))->assertForbidden();

        $this->assertTrue($cibleA->refresh()->is_active); // Rien n'a bougé.
    }

    public function test_un_admin_filiale_sans_filiale_est_fail_closed_sur_la_gestion_de_comptes(): void
    {
        // Incohérence de données (RME-7) : AdminFiliale avec filiale_id NULL. Le
        // rôle passe le middleware, mais la policy manage() et resolveTargetFiliale
        // doivent verrouiller (aucune gestion, aucune création).
        $brokenAdmin = User::factory()->create([
            'role' => UserRole::AdminFiliale, 'filiale_id' => null,
        ]);
        $orgaA = User::factory()->forFiliale($this->filialeA)->create();

        // Gérer un compte existant → 403 (manage() fail-closed).
        $this->actingAs($brokenAdmin)->patchJson(route('admin.settings.accounts.update', $orgaA), [
            'role' => 'organisateur', 'is_active' => false,
        ])->assertForbidden();
        $this->assertTrue($orgaA->refresh()->is_active);

        // Créer un compte → 422 (pas de filiale cible résoluble), aucun compte créé.
        $this->actingAs($brokenAdmin)->postJson(route('admin.settings.accounts.store'), [
            'name' => 'Y', 'email' => 'y@acs.ci', 'role' => 'organisateur',
        ])->assertStatus(422);
        $this->assertDatabaseMissing('users', ['email' => 'y@acs.ci']);
    }

    public function test_un_admin_filiale_ne_peut_pas_gerer_un_pair_admin_filiale_de_sa_propre_filiale(): void
    {
        // La policy n'autorise que la gestion des ORGANISATEUR de sa filiale : un
        // AdminFiliale ne peut ni rétrograder, ni réinitialiser, ni supprimer un
        // autre AdminFiliale — même de la même filiale (anti-prise de contrôle).
        $adminA = User::factory()->filialeAdmin()->forFiliale($this->filialeA)->create();
        $pairA = User::factory()->filialeAdmin()->forFiliale($this->filialeA)->create();

        $this->actingAs($adminA)->patchJson(route('admin.settings.accounts.update', $pairA), [
            'role' => 'organisateur', 'is_active' => false,
        ])->assertForbidden();
        $this->actingAs($adminA)->postJson(route('admin.settings.accounts.reset', $pairA))->assertForbidden();
        $this->actingAs($adminA)->deleteJson(route('admin.settings.accounts.destroy', $pairA))->assertForbidden();

        $this->assertSame(UserRole::AdminFiliale, $pairA->refresh()->role);
        $this->assertTrue($pairA->is_active);
    }

    public function test_un_admin_filiale_ne_peut_pas_gerer_un_super_admin(): void
    {
        // Un SuperAdmin (filiale_id NULL) ne doit jamais être supprimable/réinit.
        // par un AdminFiliale via la surface comptes.
        $adminA = User::factory()->filialeAdmin()->forFiliale($this->filialeA)->create();
        $super = User::factory()->superAdmin()->create();

        $this->actingAs($adminA)->deleteJson(route('admin.settings.accounts.destroy', $super))->assertForbidden();
        $this->actingAs($adminA)->postJson(route('admin.settings.accounts.reset', $super))->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $super->id]);
    }
}
