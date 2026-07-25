<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\QrMode;
use App\Http\Middleware\ApplyFilialeScope;
use App\Models\Attendance;
use App\Models\Event;
use App\Models\EventType;
use App\Models\Filiale;
use App\Models\ReportDocument;
use App\Models\Setting;
use App\Models\User;
use App\Services\Attendance\AttendanceInput;
use App\Services\AttendanceService;
use App\Services\EventTransferService;
use App\Support\Branding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AUDIT DE GATE INDÉPENDANT — preuves écrites par le security-expert (aucun rapport
 * d'agent précédent n'est présumé correct). Chaque test cible une INTERACTION entre
 * deux des 11 changements livrés en parallèle, là où l'angle mort est le plus
 * probable (chaque agent a testé son périmètre isolément).
 */
final class GateIntegrationCrossAgentTest extends TestCase
{
    use RefreshDatabase;

    private Filiale $a;

    private Filiale $b;

    private Filiale $c;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = Filiale::factory()->create(['name' => 'ACS Immobilier']);
        $this->b = Filiale::factory()->create(['name' => 'ACS Energie']);
        $this->c = Filiale::factory()->create(['name' => 'ACS Digital']);
    }

    private function type(Filiale $f, string $name = 'Atelier'): EventType
    {
        return EventType::create([
            'filiale_id' => $f->id, 'name' => $name, 'color' => '#7c3aed', 'position' => 0, 'is_active' => true,
        ]);
    }

    private function event(Filiale $f, ?EventType $type = null, ?Carbon $starts = null, ?Carbon $ends = null): Event
    {
        $type ??= $this->type($f);

        return Event::create([
            'filiale_id' => $f->id,
            'title' => 'Atelier',
            'event_type_id' => $type->id,
            'starts_at' => $starts ?? Carbon::now()->subMinutes(30),
            'ends_at' => $ends ?? Carbon::now()->addHour(),
            'qr_mode' => QrMode::Statique->value,
            'qr_secret' => Str::random(32),
            'public_slug' => 'atelier-'.Str::lower(Str::random(6)),
        ]);
    }

    // =====================================================================
    // POINT 1 — #8 (validation création stricte) vs #10 (transfert)
    // Le transfert doit appliquer EXACTEMENT la contrainte « type ∈ filiale
    // cible ». Un type d'une TROISIÈME filiale doit être rejeté, comme le fait
    // StoreEventRequest à la création.
    // =====================================================================

    public function test_transfert_rejette_un_type_dune_troisieme_filiale(): void
    {
        $super = User::factory()->superAdmin()->create();
        $event = $this->event($this->a);
        $typeC = $this->type($this->c, 'Réunion'); // type d'une filiale tierce

        $this->actingAs($super)
            ->from(route('admin.events.show', $event))
            ->post(route('admin.events.transfer', $event), [
                'filiale_id' => $this->b->id,      // on transfère vers B
                'event_type_id' => $typeC->id,     // mais avec un type de C
                'scope' => 'seance',
            ])
            ->assertSessionHasErrors('event_type_id');

        $this->assertSame($this->a->id, $event->refresh()->filiale_id, 'aucun transfert ne doit avoir lieu');
    }

    public function test_le_service_de_transfert_refuse_meme_sans_passer_par_la_requete(): void
    {
        // Défense en profondeur : le service lui-même rejette un type hors filiale
        // cible (garde miroir de la validation), indépendamment du FormRequest.
        $event = $this->event($this->a);
        $typeC = $this->type($this->c, 'Réunion');

        $this->expectException(\InvalidArgumentException::class);

        app(EventTransferService::class)
            ->transfer($event, $this->b, $typeC, false, null);
    }

    // =====================================================================
    // POINT 2 — #7 (médias privés scopés filiale) vs #10 (transfert)
    // Après transfert A→B, la route de service du document suit le NOUVEAU
    // filiale_id : l'admin de B y accède, l'admin de A (ancienne) reçoit 404,
    // le SuperAdmin (Toutes) y accède. Prouve que le scope lit filiale_id à jour.
    // =====================================================================

    public function test_service_document_suit_la_filiale_apres_transfert(): void
    {
        Storage::fake('local');

        $super = User::factory()->superAdmin()->create();
        $adminA = User::factory()->filialeAdmin()->forFiliale($this->a)->create();
        $adminB = User::factory()->filialeAdmin()->forFiliale($this->b)->create();

        $event = $this->event($this->a);
        $path = "reports/{$event->id}/documents/bilan.pdf";
        Storage::disk('local')->put($path, '%PDF-1.4 fake');
        $doc = ReportDocument::create([
            'event_id' => $event->id, 'original_name' => 'bilan.pdf', 'path' => $path,
            'mime' => 'application/pdf', 'size' => 12,
        ]);

        // AVANT transfert : admin A voit, admin B non.
        $this->actingAs($adminA)->get(route('admin.events.report.documents.show', [$event, $doc]))->assertOk();
        $this->actingAs($adminB)->get(route('admin.events.report.documents.show', [$event, $doc]))->assertNotFound();

        // Transfert A → B.
        $targetType = $this->type($this->b, 'Réunion');
        $this->actingAs($super)->post(route('admin.events.transfer', $event), [
            'filiale_id' => $this->b->id, 'event_type_id' => $targetType->id, 'scope' => 'seance',
        ])->assertRedirect(route('admin.events.index'));

        // APRÈS transfert : l'accès a basculé. Admin B voit, admin A (ancienne) non.
        $this->actingAs($adminB)->get(route('admin.events.report.documents.show', [$event, $doc]))->assertOk();
        $this->actingAs($adminA)->get(route('admin.events.report.documents.show', [$event, $doc]))->assertNotFound();
        // SuperAdmin (Toutes les filiales) : accès inconditionnel.
        $this->actingAs($super)->get(route('admin.events.report.documents.show', [$event, $doc]))->assertOk();
    }

    // =====================================================================
    // POINT 3 — #5 (EnsureActiveSession) vs #9 (contexte filiale désactivé)
    // Deux mécanismes sur deux acteurs différents. Ils ne doivent pas se
    // contredire :
    //  (a) AdminFiliale dont SA filiale est désactivée → ÉJECTÉ (login).
    //  (b) SuperAdmin dont le CONTEXTE sélectionné est désactivé → JAMAIS éjecté ;
    //      repli sur « Toutes » + avertissement.
    // =====================================================================

    public function test_admin_filiale_ejecte_si_sa_filiale_est_desactivee(): void
    {
        $admin = User::factory()->filialeAdmin()->forFiliale($this->a)->create();

        // Accès normal tant que la filiale est active.
        $this->actingAs($admin)->get(route('admin.events.index'))->assertOk();

        // Désactivation « à chaud » de sa filiale par un SuperAdmin.
        $this->a->update(['is_active' => false]);

        // Requête suivante : session révoquée → redirection login (déconnecté).
        // On recharge l'utilisateur (User::find) pour reproduire fidèlement la prod
        // où le guard résout un modèle FRAIS à chaque requête (pas de relation
        // `filiale` mise en cache d'une requête à l'autre).
        $this->actingAs(User::find($admin->id))->get(route('admin.events.index'))
            ->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_organisateur_ejecte_si_sa_filiale_est_desactivee(): void
    {
        $orga = User::factory()->forFiliale($this->a)->create();
        $this->a->update(['is_active' => false]);

        $this->actingAs($orga)->get(route('admin.events.index'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_super_admin_jamais_ejecte_quand_son_contexte_est_desactive(): void
    {
        $super = User::factory()->superAdmin()->create();

        // Le SuperAdmin a sélectionné la filiale A comme contexte d'affichage.
        $this->withSession([ApplyFilialeScope::CONTEXT_SESSION_KEY => $this->a->id]);

        // Un autre SuperAdmin désactive la filiale A.
        $this->a->update(['is_active' => false]);

        // Le SuperAdmin n'est PAS déconnecté : la page charge, contexte replié.
        $this->actingAs($super)->get(route('admin.events.index'))->assertOk();
        $this->assertAuthenticatedAs($super);
    }

    // =====================================================================
    // POINT 4 — #11 (branding par filiale) vs #10 (transfert)
    // Un événement transféré A→B doit IMMÉDIATEMENT porter le branding de B
    // (page publique + email), sans cache de session.
    // =====================================================================

    public function test_branding_public_bascule_sur_la_nouvelle_filiale_apres_transfert(): void
    {
        // Branding propre à B (héritage holding désactivé).
        Setting::set('brand_inherit', '0', $this->b->id);
        Setting::set('org_name', 'ACS Energie SA', $this->b->id);

        $super = User::factory()->superAdmin()->create();
        $event = $this->event($this->a);
        $targetType = $this->type($this->b, 'Réunion');

        // Manifeste PWA AVANT transfert : branding holding (défaut).
        $before = $this->get(route('public.attendance.manifest', ['event' => $event->public_slug]))->json('name');
        $this->assertStringContainsString('ACS Groupe', $before);

        $this->actingAs($super)->post(route('admin.events.transfer', $event), [
            'filiale_id' => $this->b->id, 'event_type_id' => $targetType->id, 'scope' => 'seance',
        ]);

        // APRÈS transfert : branding de B, résolu depuis event->filiale_id (aucune
        // session admin active sur une requête publique anonyme).
        $after = $this->get(route('public.attendance.manifest', ['event' => $event->fresh()->public_slug]))->json('name');
        $this->assertStringContainsString('ACS Energie SA', $after);

        // Preuve directe : Branding::forEvent lit la filiale à jour, sans état de session.
        $this->assertSame('ACS Energie SA', Branding::forEvent($event->fresh())->orgName);
    }

    // =====================================================================
    // POINT 5 — #2 (lock) / #4 (race) / #6-#8 : la garde anti-chevauchement
    // sous verrou est autoritaire pour TOUS les points d'entrée. Même sans le
    // pré-contrôle 409 du contrôleur, register() garantit UNE SEULE présence
    // active (clôture automatique de la précédente).
    // =====================================================================

    public function test_register_clot_automatiquement_le_chevauchement_sans_pre_controle(): void
    {
        $service = app(AttendanceService::class);
        $now = Carbon::now();

        // Personne active sur un événement en cours de la filiale A.
        $eventA = $this->event($this->a, starts: $now->copy()->subMinutes(10), ends: $now->copy()->addHour());
        $first = $service->register($eventA, new AttendanceInput(
            email: 'x@exemple.ci', lastName: 'N', firstName: 'P', phone: null, company: 'ACME',
            direction: 'Dir', service: null, position: 'Poste', signaturePath: null,
        ));
        $this->assertNull($first->departed_at);

        // Même personne émarge sur un événement EN COURS d'une AUTRE filiale (B),
        // en appelant DIRECTEMENT le service (aucun pré-contrôle 409, aucun
        // confirm_departure). L'invariant « une seule présence active » doit tenir.
        $eventB = $this->event($this->b, starts: $now->copy()->subMinutes(5), ends: $now->copy()->addHour());
        $second = $service->register($eventB, new AttendanceInput(
            email: 'x@exemple.ci', lastName: 'N', firstName: 'P', phone: null, company: 'ACME',
            direction: 'Dir', service: null, position: 'Poste', signaturePath: null,
        ));

        $this->assertNotNull($first->refresh()->departed_at, 'la présence précédente doit être clôturée sous verrou');
        $this->assertNull($second->departed_at);
        // Une seule présence active (sans départ) pour cette personne, tout ACS confondu.
        $active = Attendance::whereNull('departed_at')
            ->whereHas('person', fn ($q) => $q->where('email', 'x@exemple.ci'))->count();
        $this->assertSame(1, $active);
    }

    public function test_register_est_idempotent_et_ne_double_pas_la_presence(): void
    {
        $service = app(AttendanceService::class);
        $event = $this->event($this->a);

        $input = new AttendanceInput(
            email: 'y@exemple.ci', lastName: 'N', firstName: 'P', phone: null, company: 'ACME',
            direction: 'Dir', service: null, position: 'Poste', signaturePath: null,
        );

        $r1 = $service->register($event, $input);
        $r2 = $service->register($event, $input);

        $this->assertSame($r1->id, $r2->id, 'seconde soumission = même présence (idempotent)');
        $this->assertSame($r1->reference, $r2->reference);
        $this->assertSame(1, Attendance::where('event_id', $event->id)->count());
    }

    // =====================================================================
    // INVARIANT RME-2 — le cron n'est JAMAIS scopé par filiale : il clôt les
    // événements de TOUTES les filiales, même sans session/contexte.
    // =====================================================================

    public function test_le_cron_cloture_les_evenements_de_toutes_les_filiales(): void
    {
        $past = Carbon::now()->subHours(2);
        $eventA = $this->event($this->a, starts: $past->copy()->subHour(), ends: $past);
        $eventB = $this->event($this->b, starts: $past->copy()->subHour(), ends: $past);

        $this->artisan('events:close-due')->assertSuccessful();

        $this->assertNotNull($eventA->refresh()->closed_at, 'événement filiale A clos');
        $this->assertNotNull($eventB->refresh()->closed_at, 'événement filiale B clos');
    }
}
