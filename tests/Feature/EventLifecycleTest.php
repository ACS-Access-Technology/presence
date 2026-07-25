<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Enums\QrMode;
use App\Mail\AttendanceConfirmationMail;
use App\Models\Attendance;
use App\Models\Event;
use App\Models\EventType;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class EventLifecycleTest extends TestCase
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

    private function event(?Carbon $start = null, ?Carbon $end = null): Event
    {
        return Event::create([
            'title' => 'Atelier', 'event_type_id' => $this->type->id,
            'starts_at' => $start ?? Carbon::now()->addDay(), 'ends_at' => $end ?? Carbon::now()->addDay()->addHour(),
            'qr_mode' => QrMode::Statique->value, 'qr_secret' => Str::random(32), 'public_slug' => 'atelier-'.Str::random(5),
        ]);
    }

    public function test_annulation_et_reactivation(): void
    {
        $event = $this->event();

        $this->actingAs($this->user)->post(route('admin.events.cancel', $event), ['cancellation_reason' => 'Report sanitaire'])
            ->assertRedirect();
        $event->refresh();
        $this->assertNotNull($event->cancelled_at);
        $this->assertSame('Report sanitaire', $event->cancellation_reason);
        $this->assertSame(EventStatus::Annule, $event->status());
        $this->assertFalse($event->isOpenForCheckIn(Carbon::now()->addDay()->addMinutes(30)));

        $this->actingAs($this->user)->post(route('admin.events.uncancel', $event))->assertRedirect();
        $this->assertNull($event->refresh()->cancelled_at);
    }

    public function test_report_change_le_creneau_et_trace_l_ancien(): void
    {
        $event = $this->event(Carbon::now()->addDay()->setTime(10, 0), Carbon::now()->addDay()->setTime(11, 0));
        $oldStart = $event->starts_at->copy();

        $this->actingAs($this->user)->post(route('admin.events.reschedule', $event), [
            'date' => Carbon::now()->addDays(3)->format('Y-m-d'), 'start' => '14:00', 'end' => '16:00', 'reason' => 'Salle indisponible',
        ])->assertRedirect();

        $event->refresh();
        $this->assertSame('14:00', $event->starts_at->format('H:i'));
        $this->assertSame('16:00', $event->ends_at->format('H:i'));
        $this->assertDatabaseHas('event_reschedules', [
            'event_id' => $event->id, 'reason' => 'Salle indisponible',
        ]);
        $this->assertSame($oldStart->format('Y-m-d H:i'), $event->reschedules()->first()->old_starts_at->format('Y-m-d H:i'));
    }

    public function test_report_rouvre_un_evenement_cloture(): void
    {
        $event = $this->event(Carbon::now()->subDays(2), Carbon::now()->subDays(2)->addHour());
        $event->update(['closed_at' => Carbon::now()->subDays(2)->addHour()]);

        $this->actingAs($this->user)->post(route('admin.events.reschedule', $event), [
            'date' => Carbon::now()->addDay()->format('Y-m-d'), 'start' => '09:00', 'end' => '10:00',
        ])->assertRedirect();

        $this->assertNull($event->refresh()->closed_at);
    }

    public function test_report_rearme_le_batch_email_sans_renotifier_les_anciennes_presences(): void
    {
        Mail::fake();

        // Événement passé, clôturé + email déjà mis en file lors de la 1re clôture.
        $event = $this->event(Carbon::now()->subDays(2), Carbon::now()->subDays(2)->addHour());
        $person = Person::create(['email' => 'awa@acs.ci', 'last_name' => 'Koné', 'first_name' => 'Awa']);
        $ancienne = Attendance::create([
            'event_id' => $event->id, 'person_id' => $person->id, 'reference' => 'PRS-OLD',
            'last_name' => 'Koné', 'first_name' => 'Awa', 'phone' => '0', 'company' => 'ACS',
            'direction' => 'SI', 'position' => 'QA', 'checked_in_at' => Carbon::now()->subDays(2),
            'confirmation_email_queued_at' => Carbon::now()->subDays(2),
            'confirmation_email_sent_at' => Carbon::now()->subDays(2),
        ]);
        $event->update([
            'closed_at' => Carbon::now()->subDays(2)->addHour(),
            'report_email_queued_at' => Carbon::now()->subDays(2)->addHour(),
        ]);

        // Report vers une nouvelle date : rouvre l'événement ET réarme le batch.
        $this->actingAs($this->user)->post(route('admin.events.reschedule', $event), [
            'date' => Carbon::now()->addDay()->format('Y-m-d'), 'start' => '09:00', 'end' => '10:00',
        ])->assertRedirect();

        $event->refresh();
        $this->assertNull($event->closed_at);
        $this->assertNull($event->report_email_queued_at, 'Le batch email doit être réarmé après un report.');
        // Les présences déjà notifiées ne sont PAS réinitialisées (pas de re-notification).
        $this->assertNotNull($ancienne->refresh()->confirmation_email_sent_at);

        // Nouvelle présence sur la nouvelle date, puis re-clôture par le cron.
        $nouvellePersonne = Person::create(['email' => 'k.ndri@orange.ci', 'last_name' => 'Ndri', 'first_name' => 'Koffi']);
        Attendance::create([
            'event_id' => $event->id, 'person_id' => $nouvellePersonne->id, 'reference' => 'PRS-NEW',
            'last_name' => 'Ndri', 'first_name' => 'Koffi', 'phone' => '0', 'company' => 'ACS',
            'direction' => 'SI', 'position' => 'Dev', 'checked_in_at' => Carbon::now()->addDay()->setTime(9, 30),
        ]);
        Carbon::setTestNow(Carbon::now()->addDay()->setTime(11, 0));
        $this->artisan('events:close-due')->assertSuccessful();
        Carbon::setTestNow();

        // Seule la NOUVELLE présence est notifiée ; l'ancienne ne l'est pas à nouveau.
        Mail::assertSent(AttendanceConfirmationMail::class, 1);
        Mail::assertSent(AttendanceConfirmationMail::class, fn ($m) => $m->hasTo('k.ndri@orange.ci'));
        Mail::assertNotSent(AttendanceConfirmationMail::class, fn ($m) => $m->hasTo('awa@acs.ci'));
    }

    public function test_report_refuse_fin_avant_debut(): void
    {
        $event = $this->event();

        $this->actingAs($this->user)->post(route('admin.events.reschedule', $event), [
            'date' => Carbon::now()->addDay()->format('Y-m-d'), 'start' => '16:00', 'end' => '14:00',
        ])->assertSessionHasErrors('end');
    }

    public function test_modifie_titre_type_et_lieu(): void
    {
        $event = $this->event();
        $autreType = EventType::create(['name' => 'Réunion', 'color' => '#2563eb', 'position' => 1]);

        $this->actingAs($this->user)->patch(route('admin.events.update', $event), [
            'title' => 'Atelier Cybersécurité (corrigé)',
            'event_type_id' => $autreType->id,
            'location' => 'Salle Ébène',
            // Événement statique → périmètre exigé à la modification.
            'geofence_latitude' => '5.35', 'geofence_longitude' => '-4.01', 'geofence_radius_m' => '150',
        ])->assertRedirect();

        $event->refresh();
        $this->assertSame('Atelier Cybersécurité (corrigé)', $event->title);
        $this->assertSame($autreType->id, $event->event_type_id);
        $this->assertSame('Salle Ébène', $event->location);
    }

    public function test_modification_n_affecte_ni_horaires_ni_mode_qr(): void
    {
        $event = $this->event();
        $originalStart = $event->starts_at;
        $originalMode = $event->qr_mode;

        $this->actingAs($this->user)->patch(route('admin.events.update', $event), [
            'title' => 'Nouveau titre',
            'event_type_id' => $this->type->id,
            'geofence_latitude' => '5.35', 'geofence_longitude' => '-4.01', 'geofence_radius_m' => '150',
        ]);

        $event->refresh();
        $this->assertTrue($originalStart->equalTo($event->starts_at));
        $this->assertSame($originalMode, $event->qr_mode);
    }

    public function test_change_le_mode_qr_avant_premiere_presence(): void
    {
        $event = $this->event();
        $this->assertSame('statique', $event->qr_mode->value);

        $this->actingAs($this->user)->patch(route('admin.events.qr-mode', $event), ['qr_mode' => 'tournant'])
            ->assertRedirect();

        $this->assertSame('tournant', $event->refresh()->qr_mode->value);
    }

    /** Régression : ce endpoint ne doit pas rouvrir la faille QR statique sans géofence (T-ME / audit sécurité). */
    public function test_refuse_le_passage_en_qr_statique_sans_perimetre(): void
    {
        $event = $this->event();
        $event->update(['qr_mode' => 'tournant', 'geofence_latitude' => null, 'geofence_longitude' => null, 'geofence_radius_m' => null]);

        $this->actingAs($this->user)->patch(route('admin.events.qr-mode', $event), ['qr_mode' => 'statique'])
            ->assertRedirect();

        $this->assertSame('tournant', $event->refresh()->qr_mode->value);
    }

    public function test_accepte_le_passage_en_qr_statique_avec_perimetre(): void
    {
        $event = $this->event();
        $event->update([
            'qr_mode' => 'tournant',
            'geofence_latitude' => 5.35, 'geofence_longitude' => -4.01, 'geofence_radius_m' => 150,
        ]);

        $this->actingAs($this->user)->patch(route('admin.events.qr-mode', $event), ['qr_mode' => 'statique'])
            ->assertRedirect();

        $this->assertSame('statique', $event->refresh()->qr_mode->value);
    }

    public function test_refuse_de_changer_le_mode_qr_apres_une_presence(): void
    {
        $event = $this->event(Carbon::now()->subHour(), Carbon::now()->addHour());
        $person = Person::create(['email' => 'awa@acs.ci', 'last_name' => 'Koné', 'first_name' => 'Awa']);
        Attendance::create([
            'event_id' => $event->id, 'person_id' => $person->id, 'reference' => 'PRS001',
            'last_name' => 'Koné', 'first_name' => 'Awa', 'phone' => '0', 'company' => 'ACS', 'direction' => 'SI', 'position' => 'QA',
            'checked_in_at' => Carbon::now(),
        ]);

        $this->actingAs($this->user)->patch(route('admin.events.qr-mode', $event), ['qr_mode' => 'tournant'])
            ->assertRedirect();

        $this->assertSame('statique', $event->refresh()->qr_mode->value);
    }

    public function test_modification_refuse_un_titre_vide(): void
    {
        $event = $this->event();

        $this->actingAs($this->user)->patch(route('admin.events.update', $event), [
            'title' => '', 'event_type_id' => $this->type->id,
            'geofence_latitude' => '5.35', 'geofence_longitude' => '-4.01', 'geofence_radius_m' => '150',
        ])->assertSessionHasErrors('title');

        $this->assertSame('Atelier', $event->refresh()->title);
    }

    public function test_modification_statique_exige_le_perimetre(): void
    {
        // L'événement est en QR statique : modifier sans (re)fournir un périmètre
        // doit échouer, même pour une simple correction de titre. Empêche qu'un
        // événement statique reste sans protection anti-fraude après édition.
        $event = $this->event();

        $this->actingAs($this->user)->patch(route('admin.events.update', $event), [
            'title' => 'Titre corrigé', 'event_type_id' => $this->type->id,
        ])->assertSessionHasErrors(['geofence_latitude', 'geofence_longitude', 'geofence_radius_m']);

        $this->assertSame('Atelier', $event->refresh()->title);
    }

    public function test_modification_tournant_sans_perimetre_reste_possible(): void
    {
        $event = Event::create([
            'title' => 'Conférence', 'event_type_id' => $this->type->id,
            'starts_at' => Carbon::now()->addDay(), 'ends_at' => Carbon::now()->addDay()->addHour(),
            'qr_mode' => QrMode::Tournant->value, 'qr_secret' => Str::random(32), 'public_slug' => 'conf-'.Str::random(5),
        ]);

        $this->actingAs($this->user)->patch(route('admin.events.update', $event), [
            'title' => 'Conférence (corrigée)', 'event_type_id' => $this->type->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame('Conférence (corrigée)', $event->refresh()->title);
    }

    public function test_persiste_le_delai_de_grace_a_la_modification(): void
    {
        $event = $this->event();
        $this->assertFalse($event->refresh()->grace_check_in_enabled);

        $this->actingAs($this->user)->patch(route('admin.events.update', $event), [
            'title' => 'Atelier', 'event_type_id' => $this->type->id,
            'geofence_latitude' => '5.35', 'geofence_longitude' => '-4.01', 'geofence_radius_m' => '150',
            'grace_check_in_enabled' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertTrue($event->refresh()->grace_check_in_enabled);
    }
}
