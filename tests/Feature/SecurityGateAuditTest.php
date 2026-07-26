<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\QrMode;
use App\Models\Event;
use App\Models\EventType;
use App\Models\Filiale;
use App\Models\Scopes\FilialeScope;
use App\Models\User;
use App\Support\FilialeScoping;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PoC indépendants — gate sécurité T-ME-24 (audit gate final avant Lot H).
 * Ces tests re-dérivent les garanties de cloisonnement sans faire confiance aux
 * tests QA existants.
 */
class SecurityGateAuditTest extends TestCase
{
    use RefreshDatabase;

    private Filiale $a;

    private Filiale $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = Filiale::factory()->create(['name' => 'ACS Alpha']);
        $this->b = Filiale::factory()->create(['name' => 'ACS Beta']);
    }

    private function type(Filiale $f, string $name): EventType
    {
        return EventType::create(['filiale_id' => $f->id, 'name' => $name, 'color' => '#7c3aed', 'position' => 0, 'is_active' => true]);
    }

    private function eventIn(Filiale $f, EventType $type, string $title): Event
    {
        return Event::create([
            'filiale_id' => $f->id, 'title' => $title, 'event_type_id' => $type->id,
            'starts_at' => Carbon::now()->subHour(), 'ends_at' => Carbon::now()->addHour(),
            'qr_mode' => QrMode::Statique->value, 'qr_secret' => Str::random(32),
            'public_slug' => Str::slug($title).'-'.Str::random(5),
        ]);
    }

    // ---------------------------------------------------------------------
    // PoC 1 — event_type_id cross-filiale via `exists` (ignore le global scope)
    // ---------------------------------------------------------------------

    public function test_regression_organisateur_a_ne_peut_pas_assigner_un_type_de_filiale_b_a_la_creation(): void
    {
        $orgaA = User::factory()->forFiliale($this->a)->create();
        $this->type($this->a, 'Type A actif');
        $typeB = $this->type($this->b, 'PROJET-SECRET-BETA');

        $this->actingAs($orgaA)->post(route('admin.events.store'), [
            'title' => 'Event croisé', 'event_type_id' => $typeB->id,
            'date' => Carbon::now()->format('Y-m-d'), 'start' => '08:00', 'end' => '18:00',
            'qr_mode' => QrMode::Statique->value,
        ])->assertSessionHasErrors('event_type_id');

        // Aucun événement de A ne doit référencer le type de B.
        $this->assertSame(0, Event::withoutGlobalScope(FilialeScope::class)
            ->where('filiale_id', $this->a->id)->where('event_type_id', $typeB->id)->count());
    }

    public function test_regression_organisateur_a_ne_peut_pas_repointer_son_event_sur_un_type_de_filiale_b(): void
    {
        $orgaA = User::factory()->forFiliale($this->a)->create();
        $typeA = $this->type($this->a, 'Type A');
        $typeB = $this->type($this->b, 'PROJET-SECRET-BETA');
        $event = $this->eventIn($this->a, $typeA, 'Mon event');

        $this->actingAs($orgaA)->patch(route('admin.events.update', $event), [
            'title' => 'Mon event', 'event_type_id' => $typeB->id,
        ])->assertSessionHasErrors('event_type_id');

        $this->assertSame($typeA->id, $event->refresh()->event_type_id);
    }

    /**
     * Régression (revue indépendante post-déploiement) : `UpdateEventRequest`
     * s'appuyait sur le seul global scope (`EventType::whereKey()->exists()`),
     * qui est un no-op en contexte SuperAdmin « Toutes les filiales » — un type
     * d'une AUTRE filiale que celle de l'événement passait la validation,
     * produisant un état incohérent event.filiale_id != type.filiale_id (jamais
     * une escalade de privilège, mais une atteinte à l'intégrité avec impact
     * aval réel : accès `->type->name` sur `null` pour un AdminFiliale scopé).
     */
    public function test_regression_super_admin_en_toutes_filiales_ne_peut_pas_repointer_un_event_sur_un_type_hors_de_sa_filiale(): void
    {
        $super = User::factory()->superAdmin()->create();
        $typeA = $this->type($this->a, 'Type A');
        $typeB = $this->type($this->b, 'Type B');
        $event = $this->eventIn($this->a, $typeA, 'Event de A');

        // Contexte SuperAdmin explicitement « Toutes » (pas de filiale sélectionnée).
        $this->actingAs($super)->patch(route('admin.events.update', $event), [
            'title' => 'Event de A', 'event_type_id' => $typeB->id,
        ])->assertSessionHasErrors('event_type_id');

        $this->assertSame($typeA->id, $event->refresh()->event_type_id);
    }

    public function test_nominal_organisateur_a_peut_utiliser_un_type_de_sa_filiale(): void
    {
        $orgaA = User::factory()->forFiliale($this->a)->create();
        $typeA = $this->type($this->a, 'Type A actif');

        $this->actingAs($orgaA)->post(route('admin.events.store'), [
            'title' => 'Event OK', 'event_type_id' => $typeA->id,
            'date' => Carbon::now()->format('Y-m-d'), 'start' => '08:00', 'end' => '18:00',
            'qr_mode' => QrMode::Statique->value,
            'geofence_latitude' => '5.35', 'geofence_longitude' => '-4.01', 'geofence_radius_m' => '150',
        ])->assertRedirect();

        $this->assertSame(1, Event::withoutGlobalScope(FilialeScope::class)
            ->where('filiale_id', $this->a->id)->where('event_type_id', $typeA->id)->count());
    }

    public function test_nominal_super_admin_transversal_peut_utiliser_un_type_de_nimporte_quelle_filiale(): void
    {
        // SuperAdmin en contexte « Toutes » : le scope est no-op, tout type est
        // légitimement accessible (transversalité voulue).
        $super = User::factory()->superAdmin()->create();
        $typeB = $this->type($this->b, 'Type B');

        $this->actingAs($super)->post(route('admin.events.store'), [
            'title' => 'Event super', 'event_type_id' => $typeB->id,
            'date' => Carbon::now()->format('Y-m-d'), 'start' => '08:00', 'end' => '18:00',
            'qr_mode' => QrMode::Statique->value,
            'geofence_latitude' => '5.35', 'geofence_longitude' => '-4.01', 'geofence_radius_m' => '150',
        ])->assertRedirect();
    }

    // ---------------------------------------------------------------------
    // PoC 2 — RME-2 : le cron n'est jamais scopé (re-dérivé)
    // ---------------------------------------------------------------------

    public function test_poc_rme2_le_scope_reste_inactif_hors_requete_admin(): void
    {
        // Simule un contexte admin resté actif puis un traitement CLI : on prouve
        // que sans le middleware, le contexte singleton est inactif => aucun filtre.
        $scoping = app(FilialeScoping::class);
        $this->assertFalse($scoping->isActive(), 'Le contexte doit être inactif par défaut (cron/CLI).');

        $this->type($this->a, 'TA');
        $this->type($this->b, 'TB');
        $eA = $this->eventIn($this->a, $this->type($this->a, 'ta2'), 'A');
        $eB = $this->eventIn($this->b, $this->type($this->b, 'tb2'), 'B');

        // Requête Eloquent hors contexte admin : voit TOUTES les filiales.
        $this->assertSame(2, Event::query()->whereIn('id', [$eA->id, $eB->id])->count());
    }

    // ---------------------------------------------------------------------
    // PoC 3 — RME-7 : fail-closed (organisateur sans filiale ne voit rien)
    // ---------------------------------------------------------------------

    public function test_poc_rme7_organisateur_sans_filiale_est_fail_closed(): void
    {
        $eA = $this->eventIn($this->a, $this->type($this->a, 'ta'), 'Alpha visible');
        // Organisateur incohérent : filiale_id NULL (ne doit RIEN voir).
        $orga = User::factory()->create(['filiale_id' => null]);

        $this->actingAs($orga)->get(route('admin.events.index'))
            ->assertOk()
            ->assertDontSee('Alpha visible');

        // Accès direct à l'événement : 404 (scope 1=0).
        $this->actingAs($orga)->get(route('admin.events.show', $eA))->assertNotFound();
    }

    // ---------------------------------------------------------------------
    // PoC 4 — IDOR historique EventType : binding {type} 404 cross-filiale
    // ---------------------------------------------------------------------

    public function test_poc_idor_type_binding_cross_filiale_404(): void
    {
        $adminA = User::factory()->filialeAdmin()->forFiliale($this->a)->create();
        $typeB = $this->type($this->b, 'Type B');

        $this->actingAs($adminA)->patchJson(route('admin.settings.types.update', $typeB), [
            'name' => 'hack', 'color' => '#000000',
        ])->assertNotFound();
    }
}
