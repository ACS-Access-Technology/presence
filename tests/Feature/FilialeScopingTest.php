<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\QrMode;
use App\Enums\UserRole;
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
 * Cloisonnement par filiale (Lots A + B). Prouve l'isolation inter-filiales sur
 * les surfaces admin, le NON-scoping du cron et de la page publique (RME-2), et
 * le fail-closed pour un compte sans filiale (RME-7).
 */
class FilialeScopingTest extends TestCase
{
    use RefreshDatabase;

    private Filiale $filialeA;

    private Filiale $filialeB;

    private EventType $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->filialeA = Filiale::factory()->create(['name' => 'ACS Immobilier']);
        $this->filialeB = Filiale::factory()->create(['name' => 'ACS Digital']);
        $this->type = EventType::create(['name' => 'Atelier', 'color' => '#7c3aed', 'position' => 0]);
    }

    private function event(Filiale $filiale, string $title): Event
    {
        return Event::create([
            'filiale_id' => $filiale->id,
            'title' => $title,
            'event_type_id' => $this->type->id,
            'starts_at' => Carbon::now()->subHour(),
            'ends_at' => Carbon::now()->addHour(),
            'qr_mode' => QrMode::Statique->value,
            'qr_secret' => Str::random(32),
            'public_slug' => Str::slug($title).'-'.Str::random(5),
        ]);
    }

    // ---- Fondations données (Lot A) ----

    public function test_la_filiale_par_defaut_existe_apres_migration(): void
    {
        $default = Filiale::where('slug', Filiale::DEFAULT_SLUG)->first();
        $this->assertNotNull($default);
        $this->assertSame('ACS Groupe', $default->name);
        $this->assertSame($default->id, Filiale::defaultId());
    }

    public function test_un_evenement_cree_sans_filiale_retombe_sur_la_filiale_par_defaut(): void
    {
        $event = Event::create([
            'title' => 'Sans filiale',
            'event_type_id' => $this->type->id,
            'starts_at' => Carbon::now(),
            'ends_at' => Carbon::now()->addHour(),
            'qr_mode' => QrMode::Statique->value,
            'qr_secret' => Str::random(32),
            'public_slug' => 'sf-'.Str::random(5),
        ]);

        $this->assertSame(Filiale::defaultId(), $event->filiale_id);
    }

    public function test_un_evenement_herite_de_la_filiale_du_createur_authentifie(): void
    {
        $orga = User::factory()->forFiliale($this->filialeA)->create();
        // Le type doit appartenir à la filiale de l'orga (types strictement par
        // filiale, Q-ME-10) : c'est ce que le formulaire de création lui présente.
        $typeA = EventType::create(['filiale_id' => $this->filialeA->id, 'name' => 'Atelier A', 'color' => '#7c3aed', 'position' => 0]);

        $this->actingAs($orga)->post(route('admin.events.store'), [
            'title' => 'Créé par orga A',
            'event_type_id' => $typeA->id,
            'date' => Carbon::now()->addDay()->toDateString(),
            'start' => '09:00',
            'end' => '11:00',
            'qr_mode' => 'statique',
            'geofence_latitude' => '5.35', 'geofence_longitude' => '-4.01', 'geofence_radius_m' => '150',
        ])->assertRedirect();

        $event = Event::withoutGlobalScope(FilialeScope::class)
            ->where('title', 'Créé par orga A')->firstOrFail();
        $this->assertSame($this->filialeA->id, $event->filiale_id);
    }

    // ---- Isolation des surfaces admin (Lot B) ----

    public function test_organisateur_ne_voit_que_les_evenements_de_sa_filiale(): void
    {
        $this->event($this->filialeA, 'Evenement Alpha');
        $this->event($this->filialeB, 'Evenement Beta');

        $orga = User::factory()->forFiliale($this->filialeA)->create();

        $this->actingAs($orga)->get(route('admin.events.index'))
            ->assertOk()
            ->assertSee('Evenement Alpha')
            ->assertDontSee('Evenement Beta');
    }

    public function test_acces_croise_a_un_evenement_dune_autre_filiale_est_introuvable(): void
    {
        $eventB = $this->event($this->filialeB, 'Evenement Beta');
        $orga = User::factory()->forFiliale($this->filialeA)->create();

        // Le global scope filtre la résolution de route → 404 (pas de fuite).
        $this->actingAs($orga)->get(route('admin.events.show', $eventB))->assertNotFound();
    }

    public function test_export_croise_dune_autre_filiale_est_introuvable(): void
    {
        $eventB = $this->event($this->filialeB, 'Evenement Beta');
        $orga = User::factory()->forFiliale($this->filialeA)->create();

        $this->actingAs($orga)->get(route('admin.events.attendances.export', $eventB))->assertNotFound();
    }

    public function test_super_admin_voit_toutes_les_filiales(): void
    {
        $this->event($this->filialeA, 'Evenement Alpha');
        $eventB = $this->event($this->filialeB, 'Evenement Beta');

        $super = User::factory()->superAdmin()->create();

        $this->actingAs($super)->get(route('admin.events.index'))
            ->assertOk()
            ->assertSee('Evenement Alpha')
            ->assertSee('Evenement Beta');

        $this->actingAs($super)->get(route('admin.events.show', $eventB))->assertOk();
    }

    public function test_compte_sans_filiale_non_super_ne_voit_rien_fail_closed(): void
    {
        // Incohérence de données : Organisateur sans filiale. On ne montre rien.
        $this->event($this->filialeA, 'Evenement Alpha');
        $broken = User::factory()->create(['role' => UserRole::Organisateur, 'filiale_id' => null]);

        $this->actingAs($broken)->get(route('admin.events.index'))
            ->assertOk()
            ->assertDontSee('Evenement Alpha');
    }

    // ---- NON-scoping du cron et du public (RME-2) ----

    public function test_le_cron_traite_les_evenements_de_toutes_les_filiales(): void
    {
        $a = $this->event($this->filialeA, 'Passe A');
        $b = $this->event($this->filialeB, 'Passe B');
        $a->update(['ends_at' => Carbon::now()->subHour()]);
        $b->update(['ends_at' => Carbon::now()->subHour()]);

        $this->artisan('events:close-due')->assertSuccessful();

        $this->assertNotNull($a->refresh()->closed_at);
        $this->assertNotNull($b->refresh()->closed_at);
    }

    public function test_la_page_publique_nest_pas_scopee_par_filiale(): void
    {
        $eventB = $this->event($this->filialeB, 'Evenement Public Beta');

        // Aucun utilisateur authentifié : le contexte de scoping est inactif.
        $this->get(route('public.attendance.show', $eventB->public_slug))->assertOk();
    }
}
