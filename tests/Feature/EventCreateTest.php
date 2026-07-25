<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PersonSource;
use App\Mail\EventInvitationMail;
use App\Models\Event;
use App\Models\EventSeries;
use App\Models\EventType;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EventCreateTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private EventType $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->type = EventType::create(['name' => 'Atelier', 'color' => '#7c3aed', 'position' => 0]);
    }

    public function test_affiche_le_formulaire_de_creation(): void
    {
        $this->actingAs($this->user)->get(route('admin.events.create'))
            ->assertOk()
            ->assertSee('id="create-sheet"', false)
            ->assertSee('Nouvel événement')
            ->assertSee('Créer l\'événement', false);
    }

    public function test_mode_overlay_affiche_le_formulaire_sans_panneau_imbrique(): void
    {
        $this->actingAs($this->user)
            ->get(route('admin.events.create', ['overlay' => 1]))
            ->assertOk()
            ->assertSee('overlay-page', false)
            ->assertDontSee('id="create-sheet"', false)
            ->assertSee('Créer l\'événement', false);
    }

    public function test_cree_un_evenement_avec_invites(): void
    {
        Mail::fake();
        $person = Person::create([
            'email' => 'awa.kone@acsgroupe.ci', 'last_name' => 'Koné', 'first_name' => 'Awa',
            'is_staff' => true, 'source' => PersonSource::Import,
        ]);

        $response = $this->actingAs($this->user)->post(route('admin.events.store'), [
            'title' => 'Atelier Cybersécurité',
            'event_type_id' => $this->type->id,
            'date' => now()->addDay()->toDateString(),
            'start' => '09:00',
            'end' => '11:00',
            'location' => 'Salle Ébène',
            'qr_mode' => 'tournant',
            'invitees' => [$person->id],
        ]);

        $event = Event::firstOrFail();
        $response->assertRedirect(route('admin.events.show', $event));
        $response->assertSessionHas('status', 'Événement créé.');

        $this->assertSame('Atelier Cybersécurité', $event->title);
        $this->assertSame('tournant', $event->qr_mode->value);
        $this->assertNotEmpty($event->qr_secret);
        $this->assertNotEmpty($event->public_slug);
        $this->assertSame($this->user->id, $event->created_by);
        $this->assertSame(1, $event->invitations()->count());
        $invitation = $event->invitations()->first();
        $this->assertSame($person->id, $invitation->person_id);
        $this->assertNotNull($invitation->notified_at);

        Mail::assertQueued(EventInvitationMail::class, fn (EventInvitationMail $mail): bool => $mail->invitation->is($invitation));
    }

    public function test_genere_un_slug_unique_en_cas_de_titres_identiques(): void
    {
        $payload = [
            'event_type_id' => $this->type->id,
            'date' => now()->addDay()->toDateString(),
            'start' => '09:00',
            'end' => '11:00',
            'qr_mode' => 'statique',
            'geofence_latitude' => '5.35', 'geofence_longitude' => '-4.01', 'geofence_radius_m' => '150',
        ];

        $this->actingAs($this->user)->post(route('admin.events.store'), ['title' => 'Réunion Comité SI'] + $payload);
        $this->actingAs($this->user)->post(route('admin.events.store'), ['title' => 'Réunion Comité SI'] + $payload);

        $slugs = Event::pluck('public_slug');
        $this->assertCount(2, $slugs);
        $this->assertSame($slugs->count(), $slugs->unique()->count());
    }

    public function test_cree_un_evenement_avec_perimetre_anti_fraude(): void
    {
        $this->actingAs($this->user)->post(route('admin.events.store'), [
            'title' => 'Atelier', 'event_type_id' => $this->type->id,
            'date' => now()->addDay()->toDateString(), 'start' => '09:00', 'end' => '11:00',
            'qr_mode' => 'statique',
            'geofence_latitude' => '5.35',
            'geofence_longitude' => '-4.01',
            'geofence_radius_m' => '150',
        ]);

        $event = Event::firstOrFail();
        $this->assertSame(5.35, $event->geofence_latitude);
        $this->assertSame(-4.01, $event->geofence_longitude);
        $this->assertSame(150, $event->geofence_radius_m);
        $this->assertTrue($event->hasGeofence());
    }

    public function test_refuse_un_evenement_statique_sans_perimetre(): void
    {
        // Un QR statique sans géofence n'a aucune protection anti-photo-à-distance :
        // la validation doit l'exiger, avec un message explicite.
        $response = $this->actingAs($this->user)->post(route('admin.events.store'), [
            'title' => 'Atelier', 'event_type_id' => $this->type->id,
            'date' => now()->addDay()->toDateString(), 'start' => '09:00', 'end' => '11:00',
            'qr_mode' => 'statique',
        ]);

        $response->assertSessionHasErrors(['geofence_latitude', 'geofence_longitude', 'geofence_radius_m']);
        $this->assertStringContainsString(
            'QR statique',
            session('errors')->first('geofence_latitude'),
        );
        $this->assertSame(0, Event::count());
    }

    public function test_accepte_un_evenement_tournant_sans_perimetre(): void
    {
        // En QR tournant, le token HMAC expire toutes les 15 s : une photo est
        // inutile, le périmètre reste donc facultatif.
        $this->actingAs($this->user)->post(route('admin.events.store'), [
            'title' => 'Conférence', 'event_type_id' => $this->type->id,
            'date' => now()->addDay()->toDateString(), 'start' => '09:00', 'end' => '11:00',
            'qr_mode' => 'tournant',
        ])->assertSessionHasNoErrors();

        $event = Event::firstOrFail();
        $this->assertSame('tournant', $event->qr_mode->value);
        $this->assertFalse($event->hasGeofence());
    }

    public function test_persiste_le_delai_de_grace_a_la_creation(): void
    {
        $this->actingAs($this->user)->post(route('admin.events.store'), [
            'title' => 'Atelier', 'event_type_id' => $this->type->id,
            'date' => now()->addDay()->toDateString(), 'start' => '09:00', 'end' => '11:00',
            'qr_mode' => 'tournant',
            'grace_check_in_enabled' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Event::firstOrFail()->grace_check_in_enabled);
    }

    public function test_delai_de_grace_desactive_par_defaut(): void
    {
        $this->actingAs($this->user)->post(route('admin.events.store'), [
            'title' => 'Atelier', 'event_type_id' => $this->type->id,
            'date' => now()->addDay()->toDateString(), 'start' => '09:00', 'end' => '11:00',
            'qr_mode' => 'tournant',
            'grace_check_in_enabled' => '0',
        ])->assertSessionHasNoErrors();

        $this->assertFalse(Event::firstOrFail()->grace_check_in_enabled);
    }

    public function test_rejette_un_perimetre_partiellement_renseigne(): void
    {
        $this->actingAs($this->user)->post(route('admin.events.store'), [
            'title' => 'Atelier', 'event_type_id' => $this->type->id,
            'date' => now()->addDay()->toDateString(), 'start' => '09:00', 'end' => '11:00',
            'qr_mode' => 'statique',
            'geofence_latitude' => '5.35',
        ])->assertSessionHasErrors(['geofence_longitude', 'geofence_radius_m']);

        $this->assertSame(0, Event::count());
    }

    public function test_rejette_une_fin_avant_le_debut(): void
    {
        $this->actingAs($this->user)->post(route('admin.events.store'), [
            'title' => 'Atelier', 'event_type_id' => $this->type->id,
            'date' => now()->addDay()->toDateString(), 'start' => '11:00', 'end' => '09:00',
            'qr_mode' => 'statique',
        ])->assertSessionHasErrors('end');

        $this->assertSame(0, Event::count());
    }

    public function test_cree_une_serie_de_seances_multiples(): void
    {
        $response = $this->actingAs($this->user)->post(route('admin.events.store'), [
            'title' => 'Formation Cybersécurité', 'event_type_id' => $this->type->id,
            'date' => now()->addDay()->toDateString(), 'start' => '09:00', 'end' => '12:00',
            'location' => 'Salle Ébène', 'qr_mode' => 'statique',
            'geofence_latitude' => '5.35', 'geofence_longitude' => '-4.01', 'geofence_radius_m' => '150',
            'extra_seances' => [
                ['date' => now()->addDays(2)->toDateString(), 'start' => '09:00', 'end' => '12:00'],
                ['date' => now()->addDays(3)->toDateString(), 'start' => '09:00', 'end' => '12:00'],
            ],
        ]);

        $events = Event::orderBy('series_position')->get();
        $this->assertCount(3, $events);
        $this->assertSame($events->first()->id, Event::firstOrFail()->id);
        $response->assertSessionHas('status', 'Série créée : 3 séances.');

        $seriesId = $events->first()->event_series_id;
        $this->assertNotNull($seriesId);
        $this->assertTrue($events->every(fn (Event $e) => $e->event_series_id === $seriesId));
        $this->assertSame([1, 2, 3], $events->pluck('series_position')->all());
        // Chaque séance garde son propre QR et son propre slug public.
        $this->assertSame(3, $events->pluck('qr_secret')->unique()->count());
        $this->assertSame(3, $events->pluck('public_slug')->unique()->count());
    }

    public function test_sans_extra_seances_ne_cree_pas_de_serie(): void
    {
        $this->actingAs($this->user)->post(route('admin.events.store'), [
            'title' => 'Réunion simple', 'event_type_id' => $this->type->id,
            'date' => now()->addDay()->toDateString(), 'start' => '09:00', 'end' => '10:00',
            'qr_mode' => 'statique',
            'geofence_latitude' => '5.35', 'geofence_longitude' => '-4.01', 'geofence_radius_m' => '150',
        ]);

        $event = Event::firstOrFail();
        $this->assertNull($event->event_series_id);
        $this->assertNull($event->series_position);
        $this->assertSame(0, EventSeries::count());
    }

    public function test_invitations_propagees_a_toutes_les_seances(): void
    {
        $person = Person::create(['email' => 'awa@acsgroupe.ci', 'last_name' => 'Koné', 'first_name' => 'Awa', 'is_staff' => true, 'source' => PersonSource::Import]);

        $this->actingAs($this->user)->post(route('admin.events.store'), [
            'title' => 'Formation', 'event_type_id' => $this->type->id,
            'date' => now()->addDay()->toDateString(), 'start' => '09:00', 'end' => '12:00',
            'qr_mode' => 'statique',
            'geofence_latitude' => '5.35', 'geofence_longitude' => '-4.01', 'geofence_radius_m' => '150',
            'extra_seances' => [['date' => now()->addDays(2)->toDateString(), 'start' => '09:00', 'end' => '12:00']],
            'invitees' => [$person->id],
        ]);

        foreach (Event::all() as $event) {
            $this->assertSame(1, $event->invitations()->where('person_id', $person->id)->count());
        }
    }

    public function test_fiche_evenement_affiche_les_seances_soeurs(): void
    {
        $this->actingAs($this->user)->post(route('admin.events.store'), [
            'title' => 'Formation', 'event_type_id' => $this->type->id,
            'date' => now()->addDay()->toDateString(), 'start' => '09:00', 'end' => '12:00',
            'qr_mode' => 'statique',
            'geofence_latitude' => '5.35', 'geofence_longitude' => '-4.01', 'geofence_radius_m' => '150',
            'extra_seances' => [['date' => now()->addDays(2)->toDateString(), 'start' => '09:00', 'end' => '12:00']],
        ]);

        $first = Event::orderBy('series_position')->firstOrFail();

        $this->actingAs($this->user)->get(route('admin.events.show', $first))
            ->assertOk()
            ->assertSee('Séance 1')
            ->assertSee('série de 2');
    }

    public function test_recherche_de_personnel_pour_l_invitation(): void
    {
        Person::create(['email' => 'awa.kone@acsgroupe.ci', 'last_name' => 'Koné', 'first_name' => 'Awa', 'is_staff' => true, 'source' => PersonSource::Import]);
        Person::create(['email' => 'f.diallo@nsia.ci', 'last_name' => 'Diallo', 'first_name' => 'Fatou', 'is_staff' => false, 'source' => PersonSource::Emargement]);

        $response = $this->actingAs($this->user)->getJson(route('admin.people.search', ['q' => 'Koné']));

        $response->assertOk();
        $people = $response->json('people');
        $this->assertCount(1, $people);
        $this->assertSame('Awa Koné', $people[0]['name']);
    }
}
