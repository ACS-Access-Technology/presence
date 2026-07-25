<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\ApplyFilialeScope;
use App\Models\Event;
use App\Models\EventType;
use App\Models\Filiale;
use App\Models\Scopes\FilialeScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Rattachement EXPLICITE d'un événement / type à une filiale (P1) et robustesse du
 * sélecteur de contexte face à une filiale désactivée (P1).
 *
 * Prouve qu'aucun rattachement silencieux à la filiale par défaut « ACS Groupe »
 * n'a plus lieu depuis un contexte « Toutes les filiales », et qu'une filiale
 * désactivée ne peut plus être sélectionnée ni rester active en session sans
 * avertissement.
 */
class MultiFilialeCreationContextTest extends TestCase
{
    use RefreshDatabase;

    private Filiale $filialeA;

    private Filiale $filialeB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->filialeA = Filiale::factory()->create(['name' => 'ACS Immobilier']);
        $this->filialeB = Filiale::factory()->create(['name' => 'ACS Energie']);
    }

    private function type(Filiale $filiale, string $name): EventType
    {
        return EventType::create(['filiale_id' => $filiale->id, 'name' => $name, 'color' => '#7c3aed', 'position' => 0]);
    }

    /** @param array<string, mixed> $overrides */
    private function eventPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Atelier',
            'date' => Carbon::now()->addDay()->toDateString(),
            'start' => '09:00',
            'end' => '11:00',
            'qr_mode' => 'statique',
            'geofence_latitude' => '5.35', 'geofence_longitude' => '-4.01', 'geofence_radius_m' => '150',
        ], $overrides);
    }

    private function selectContext(User $super, ?int $filialeId): void
    {
        $this->actingAs($super)->post(route('admin.filiale-context.update'), ['filiale_id' => $filialeId]);
    }

    // ======================================================================
    // Anomalie 1 — Filiale explicite à la création d'un ÉVÉNEMENT
    // ======================================================================

    public function test_super_admin_en_toutes_filiales_doit_choisir_une_filiale(): void
    {
        $super = User::factory()->superAdmin()->create();
        $typeA = $this->type($this->filialeA, 'Atelier A');

        $this->actingAs($super)->post(route('admin.events.store'), $this->eventPayload([
            'event_type_id' => $typeA->id,
            // Pas de filiale_id : aucune filiale déterminable → refus explicite.
        ]))->assertSessionHasErrors('filiale_id');

        $this->assertSame(0, Event::withoutGlobalScope(FilialeScope::class)->count());
    }

    public function test_super_admin_cree_un_evenement_dans_la_filiale_choisie(): void
    {
        $super = User::factory()->superAdmin()->create();
        $typeA = $this->type($this->filialeA, 'Atelier A');

        $this->actingAs($super)->post(route('admin.events.store'), $this->eventPayload([
            'event_type_id' => $typeA->id,
            'filiale_id' => (string) $this->filialeA->id,
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $event = Event::withoutGlobalScope(FilialeScope::class)->firstOrFail();
        $this->assertSame($this->filialeA->id, $event->filiale_id);
    }

    public function test_super_admin_avec_contexte_cree_dans_la_filiale_du_contexte_et_ignore_le_payload(): void
    {
        $super = User::factory()->superAdmin()->create();
        $typeA = $this->type($this->filialeA, 'Atelier A');
        $this->selectContext($super, $this->filialeA->id);

        // Le contexte topbar fixe A : une tentative d'injection de B dans le payload
        // est ignorée (le contexte prime), l'événement est bien créé dans A.
        $this->actingAs($super)->post(route('admin.events.store'), $this->eventPayload([
            'event_type_id' => $typeA->id,
            'filiale_id' => (string) $this->filialeB->id,
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $event = Event::withoutGlobalScope(FilialeScope::class)->firstOrFail();
        $this->assertSame($this->filialeA->id, $event->filiale_id);
    }

    public function test_creation_refuse_un_type_dune_autre_filiale(): void
    {
        $super = User::factory()->superAdmin()->create();
        $typeB = $this->type($this->filialeB, 'Atelier B');

        // Filiale cible A mais type de B : incohérence rejetée (anti-fuite inter-filiale),
        // y compris en « Toutes » où le global scope ne filtre pas.
        $this->actingAs($super)->post(route('admin.events.store'), $this->eventPayload([
            'event_type_id' => $typeB->id,
            'filiale_id' => (string) $this->filialeA->id,
        ]))->assertSessionHasErrors('event_type_id');

        $this->assertSame(0, Event::withoutGlobalScope(FilialeScope::class)->count());
    }

    public function test_creation_refuse_une_filiale_desactivee(): void
    {
        $super = User::factory()->superAdmin()->create();
        $typeA = $this->type($this->filialeA, 'Atelier A');
        $this->filialeA->update(['is_active' => false]);

        $this->actingAs($super)->post(route('admin.events.store'), $this->eventPayload([
            'event_type_id' => $typeA->id,
            'filiale_id' => (string) $this->filialeA->id,
        ]))->assertSessionHasErrors('filiale_id');

        $this->assertSame(0, Event::withoutGlobalScope(FilialeScope::class)->count());
    }

    public function test_creation_en_serie_applique_la_filiale_a_toutes_les_seances(): void
    {
        $super = User::factory()->superAdmin()->create();
        $typeA = $this->type($this->filialeA, 'Atelier A');

        $this->actingAs($super)->post(route('admin.events.store'), $this->eventPayload([
            'event_type_id' => $typeA->id,
            'filiale_id' => (string) $this->filialeA->id,
            'extra_seances' => [
                ['date' => Carbon::now()->addDays(2)->toDateString(), 'start' => '09:00', 'end' => '11:00'],
                ['date' => Carbon::now()->addDays(3)->toDateString(), 'start' => '09:00', 'end' => '11:00'],
            ],
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $events = Event::withoutGlobalScope(FilialeScope::class)->get();
        $this->assertCount(3, $events);
        $this->assertTrue($events->every(fn (Event $e) => $e->filiale_id === $this->filialeA->id));
    }

    public function test_organisateur_ne_peut_pas_injecter_une_autre_filiale_a_la_creation(): void
    {
        $orga = User::factory()->forFiliale($this->filialeA)->create();
        $typeA = $this->type($this->filialeA, 'Atelier A');

        // Tentative d'injection de B : ignorée, l'événement reste dans la filiale A.
        $this->actingAs($orga)->post(route('admin.events.store'), $this->eventPayload([
            'event_type_id' => $typeA->id,
            'filiale_id' => (string) $this->filialeB->id,
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $event = Event::withoutGlobalScope(FilialeScope::class)->firstOrFail();
        $this->assertSame($this->filialeA->id, $event->filiale_id);
    }

    public function test_le_formulaire_de_creation_affiche_la_filiale_de_contexte_en_lecture_seule(): void
    {
        $super = User::factory()->superAdmin()->create();
        $this->type($this->filialeA, 'Atelier A');
        $this->selectContext($super, $this->filialeA->id);

        $this->actingAs($super)->get(route('admin.events.create'))
            ->assertOk()
            ->assertSee('Cet événement sera créé dans')
            ->assertSee('ACS Immobilier');
    }

    public function test_le_formulaire_de_creation_en_toutes_filiales_impose_un_choix(): void
    {
        $super = User::factory()->superAdmin()->create();
        $this->type($this->filialeA, 'Atelier A');

        $this->actingAs($super)->get(route('admin.events.create'))
            ->assertOk()
            ->assertSee('name="filiale_id"', false);
    }

    // ======================================================================
    // Anomalie 1 — Filiale explicite à la création d'un TYPE d'événement
    // ======================================================================

    public function test_super_admin_en_toutes_filiales_doit_choisir_une_filiale_pour_un_type(): void
    {
        $super = User::factory()->superAdmin()->create();

        $this->actingAs($super)->postJson(route('admin.settings.types.store'), [
            'name' => 'Séminaire', 'color' => '#123456',
        ])->assertStatus(422)->assertJsonValidationErrors('filiale_id');

        $this->assertSame(0, EventType::withoutGlobalScope(FilialeScope::class)->where('name', 'Séminaire')->count());
    }

    public function test_super_admin_cree_un_type_dans_la_filiale_choisie(): void
    {
        $super = User::factory()->superAdmin()->create();

        $this->actingAs($super)->postJson(route('admin.settings.types.store'), [
            'name' => 'Séminaire', 'color' => '#123456', 'filiale_id' => (string) $this->filialeB->id,
        ])->assertStatus(201);

        $this->assertDatabaseHas('event_types', [
            'name' => 'Séminaire', 'filiale_id' => $this->filialeB->id,
        ]);
    }

    public function test_super_admin_avec_contexte_cree_un_type_dans_la_filiale_du_contexte(): void
    {
        $super = User::factory()->superAdmin()->create();
        $this->selectContext($super, $this->filialeA->id);

        $this->actingAs($super)->postJson(route('admin.settings.types.store'), [
            'name' => 'Séminaire', 'color' => '#123456',
        ])->assertStatus(201);

        $this->assertDatabaseHas('event_types', [
            'name' => 'Séminaire', 'filiale_id' => $this->filialeA->id,
        ]);
    }

    // ======================================================================
    // Anomalie 2 — Sélecteur de contexte face à une filiale désactivée
    // ======================================================================

    public function test_le_selecteur_refuse_une_filiale_desactivee(): void
    {
        $super = User::factory()->superAdmin()->create();
        $this->filialeA->update(['is_active' => false]);

        $this->actingAs($super)->post(route('admin.filiale-context.update'), [
            'filiale_id' => $this->filialeA->id,
        ])->assertSessionHasErrors('filiale_id');

        // Le contexte n'a pas été posé.
        $this->assertNull(session(ApplyFilialeScope::CONTEXT_SESSION_KEY));
    }

    public function test_le_selecteur_marque_les_filiales_desactivees_non_selectionnables(): void
    {
        $super = User::factory()->superAdmin()->create();
        $this->filialeA->update(['is_active' => false]);

        $html = $this->actingAs($super)->get(route('admin.events.index'))->assertOk()->getContent();

        // L'option de la filiale désactivée est présente mais disabled.
        $this->assertMatchesRegularExpression(
            '/<option[^>]*value="'.$this->filialeA->id.'"[^>]*\bdisabled\b/',
            (string) $html,
        );
    }

    public function test_un_contexte_pointant_une_filiale_desactivee_retombe_sur_toutes_avec_avertissement(): void
    {
        $super = User::factory()->superAdmin()->create();
        $eventA = Event::create([
            'filiale_id' => $this->filialeA->id, 'title' => 'Evenement Alpha',
            'event_type_id' => $this->type($this->filialeA, 'T')->id,
            'starts_at' => Carbon::now()->subHour(), 'ends_at' => Carbon::now()->addHour(),
            'qr_mode' => 'statique', 'qr_secret' => Str::random(32),
            'public_slug' => 'alpha-'.Str::random(5),
        ]);
        $eventB = Event::create([
            'filiale_id' => $this->filialeB->id, 'title' => 'Evenement Beta',
            'event_type_id' => $this->type($this->filialeB, 'T')->id,
            'starts_at' => Carbon::now()->subHour(), 'ends_at' => Carbon::now()->addHour(),
            'qr_mode' => 'statique', 'qr_secret' => Str::random(32),
            'public_slug' => 'beta-'.Str::random(5),
        ]);

        // Le super sélectionne A (active), puis A est désactivée après coup.
        $this->selectContext($super, $this->filialeA->id);
        $this->filialeA->update(['is_active' => false]);

        // Requête suivante : repli explicite sur « Toutes » (voit A ET B) + avertissement.
        $response = $this->actingAs($super)->get(route('admin.events.index'))->assertOk();
        $response->assertSee('Evenement Alpha')->assertSee('Evenement Beta');
        $response->assertSee('désactivée');

        // La sélection invalide a été purgée de la session.
        $this->assertNull(session(ApplyFilialeScope::CONTEXT_SESSION_KEY));
    }
}
