<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\EventRescheduledMail;
use App\Models\Attendance;
use App\Models\Event;
use App\Models\EventSeries;
use App\Models\EventType;
use App\Models\Person;
use App\Models\User;
use App\Support\IcsBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Notification des invités (et présents) lors du report d'un événement (P2).
 */
class EventRescheduleNotificationTest extends TestCase
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

    private function event(string $mode = 'statique', ?Carbon $start = null, ?Carbon $end = null): Event
    {
        return Event::create([
            'title' => 'Atelier', 'event_type_id' => $this->type->id,
            'starts_at' => $start ?? Carbon::now()->addDay()->setTime(10, 0),
            'ends_at' => $end ?? Carbon::now()->addDay()->setTime(11, 0),
            'qr_mode' => $mode, 'qr_secret' => Str::random(32), 'public_slug' => 'atelier-'.Str::random(5),
        ]);
    }

    private function person(string $email, string $first = 'Awa', string $last = 'Koné'): Person
    {
        return Person::create(['email' => $email, 'last_name' => $last, 'first_name' => $first]);
    }

    private function attendance(Event $event, Person $person, string $reference): Attendance
    {
        return Attendance::create([
            'event_id' => $event->id, 'person_id' => $person->id, 'reference' => $reference,
            'last_name' => $person->last_name, 'first_name' => $person->first_name, 'phone' => '0',
            'company' => 'ACS', 'direction' => 'SI', 'position' => 'QA', 'checked_in_at' => Carbon::now(),
        ]);
    }

    private function reschedule(Event $event, array $override = []): void
    {
        $this->actingAs($this->user)->post(route('admin.events.reschedule', $event), array_merge([
            'date' => Carbon::now()->addDays(3)->format('Y-m-d'), 'start' => '14:00', 'end' => '16:00',
            'reason' => 'Salle indisponible',
        ], $override))->assertRedirect();
    }

    public function test_report_notifie_tous_les_invites_avec_ancien_et_nouveau_creneau(): void
    {
        Mail::fake();
        $event = $this->event('statique', Carbon::now()->addDay()->setTime(10, 0), Carbon::now()->addDay()->setTime(11, 0));
        $a = $this->person('a@acs.ci');
        $b = $this->person('b@acs.ci', 'Koffi', 'Ndri');
        $event->invitations()->create(['person_id' => $a->id]);
        $event->invitations()->create(['person_id' => $b->id]);

        $this->reschedule($event);

        Mail::assertQueued(EventRescheduledMail::class, 2);
        foreach (['a@acs.ci', 'b@acs.ci'] as $email) {
            Mail::assertQueued(EventRescheduledMail::class, fn (EventRescheduledMail $m): bool => $m->hasTo($email));
        }

        // Le mail porte bien l'ancien ET le nouveau créneau (snapshot immuable).
        Mail::assertQueued(EventRescheduledMail::class, function (EventRescheduledMail $m): bool {
            $html = $m->render();

            return str_contains($html, $m->reschedule->old_starts_at->translatedFormat('j F Y'))
                && str_contains($html, '14:00')
                && str_contains($html, '16:00');
        });
    }

    public function test_report_notifie_aussi_les_presences_deja_enregistrees(): void
    {
        Mail::fake();
        // Événement passé/clôturé, une personne a déjà émargé (aucune invitation).
        $event = $this->event('statique', Carbon::now()->subDays(2), Carbon::now()->subDays(2)->addHour());
        $event->update(['closed_at' => Carbon::now()->subDays(2)->addHour()]);
        $present = $this->person('present@acs.ci');
        $this->attendance($event, $present, 'PRS-OLD');

        $this->reschedule($event, ['date' => Carbon::now()->addDay()->format('Y-m-d')]);

        Mail::assertQueued(EventRescheduledMail::class, 1);
        Mail::assertQueued(EventRescheduledMail::class, fn (EventRescheduledMail $m): bool => $m->hasTo('present@acs.ci'));
    }

    public function test_report_ne_duplique_pas_pour_une_personne_invitee_et_presente(): void
    {
        Mail::fake();
        $event = $this->event('statique', Carbon::now()->subDay(), Carbon::now()->subDay()->addHour());
        $p = $this->person('both@acs.ci');
        $event->invitations()->create(['person_id' => $p->id]);
        $this->attendance($event, $p, 'PRS-BOTH');

        $this->reschedule($event, ['date' => Carbon::now()->addDay()->format('Y-m-d')]);

        // Un seul email malgré la double appartenance (invité + présent).
        Mail::assertQueued(EventRescheduledMail::class, 1);
    }

    /**
     * Aligné sur EventInvitationMail : quel que soit le mode QR, aucun lien direct
     * d'émargement n'est envoyé par email (diffuser un lien statique par email
     * contournerait l'anti-fraude « scan sur place » — même décision, même raison).
     */
    public function test_report_ninclut_jamais_de_lien_direct_quel_que_soit_le_mode(): void
    {
        Mail::fake();
        foreach (['tournant', 'statique'] as $mode) {
            $event = $this->event($mode);
            $p = $this->person($mode.'@acs.ci');
            $event->invitations()->create(['person_id' => $p->id]);

            $this->reschedule($event);

            Mail::assertQueued(EventRescheduledMail::class, function (EventRescheduledMail $m) use ($event): bool {
                return ! str_contains($m->render(), $event->public_slug);
            });
        }
    }

    public function test_ics_de_report_est_une_mise_a_jour_request_avec_sequence_et_uid_stable(): void
    {
        $event = $this->event('statique');
        $start = Carbon::now()->addDays(3)->setTime(14, 0);
        $end = Carbon::now()->addDays(3)->setTime(16, 0);

        $ics1 = IcsBuilder::rescheduleForEvent($event, $start, $end, 1, 'invite@acs.ci');
        $ics2 = IcsBuilder::rescheduleForEvent($event, $start, $end, 2, 'invite@acs.ci');

        $this->assertStringContainsString('METHOD:REQUEST', $ics1);
        $this->assertStringContainsString('SEQUENCE:1', $ics1);
        $this->assertStringContainsString('SEQUENCE:2', $ics2);
        $this->assertStringContainsString('ATTENDEE', $ics1);
        // UID identique à celui de l'invitation initiale → les clients calendrier
        // mettent à jour l'entrée existante au lieu de créer un doublon.
        $uid = 'event-'.$event->id.'@';
        $this->assertStringContainsString('UID:'.$uid, $ics1);
        $this->assertStringContainsString('UID:'.$uid, IcsBuilder::forEvent($event));
    }

    public function test_report_d_une_seance_ne_notifie_pas_les_autres_seances(): void
    {
        Mail::fake();
        $series = EventSeries::create(['title' => 'Série', 'event_type_id' => $this->type->id]);
        $seanceA = $this->event('statique');
        $seanceB = $this->event('statique', Carbon::now()->addDays(2)->setTime(10, 0), Carbon::now()->addDays(2)->setTime(11, 0));
        $seanceA->update(['event_series_id' => $series->id, 'series_position' => 1]);
        $seanceB->update(['event_series_id' => $series->id, 'series_position' => 2]);

        $p = $this->person('serie@acs.ci');
        // La personne est invitée aux DEUX séances (comme le fait EventController::store).
        $seanceA->invitations()->create(['person_id' => $p->id]);
        $seanceB->invitations()->create(['person_id' => $p->id]);

        // On ne reporte QUE la séance A.
        $this->reschedule($seanceA);

        // Un seul email : celui de la séance A. La séance B n'est pas notifiée.
        Mail::assertQueued(EventRescheduledMail::class, 1);
        Mail::assertQueued(EventRescheduledMail::class, fn (EventRescheduledMail $m): bool => $m->reschedule->event_id === $seanceA->id);
    }
}
