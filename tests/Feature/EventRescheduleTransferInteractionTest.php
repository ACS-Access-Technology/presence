<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\QrMode;
use App\Mail\EventRescheduledMail;
use App\Models\Attendance;
use App\Models\Event;
use App\Models\EventType;
use App\Models\Filiale;
use App\Models\Person;
use App\Models\User;
use App\Services\EventTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Combinaisons report ↔ transfert (deux opérations du cycle de vie qui touchent
 * des colonnes différentes de `events`) et invariants QR au report. Ces scénarios
 * croisés ne sont couverts ni par EventLifecycleTest, ni par EventTransferTest,
 * qui les testent isolément.
 */
final class EventRescheduleTransferInteractionTest extends TestCase
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

    private function type(Filiale $filiale, string $name = 'Atelier'): EventType
    {
        return EventType::create([
            'filiale_id' => $filiale->id, 'name' => $name, 'color' => '#7c3aed', 'position' => 0, 'is_active' => true,
        ]);
    }

    private function event(Filiale $filiale, EventType $type): Event
    {
        return Event::create([
            'filiale_id' => $filiale->id,
            'title' => 'Atelier',
            'event_type_id' => $type->id,
            'starts_at' => Carbon::now()->addDay()->setTime(10, 0),
            'ends_at' => Carbon::now()->addDay()->setTime(11, 0),
            'qr_mode' => QrMode::Statique->value,
            'qr_secret' => Str::random(32),
            'public_slug' => 'atelier-'.Str::lower(Str::random(6)),
        ]);
    }

    private function attend(Event $event, string $email): Attendance
    {
        $person = Person::create(['email' => $email, 'last_name' => 'Nom', 'first_name' => 'Prénom']);

        return Attendance::create([
            'event_id' => $event->id, 'person_id' => $person->id,
            'last_name' => 'Nom', 'first_name' => 'Prénom',
            'checked_in_at' => Carbon::now(), 'reference' => 'PRS-'.Str::upper(Str::random(6)),
        ]);
    }

    // ======================================================================
    // Invariant QR : le report ne touche PAS la racine HMAC
    // ======================================================================

    public function test_le_report_conserve_le_secret_qr_et_le_slug_public(): void
    {
        Mail::fake();
        $user = User::factory()->superAdmin()->create();
        $event = $this->event($this->filialeA, $this->type($this->filialeA));
        $secretAvant = $event->qr_secret;
        $slugAvant = $event->public_slug;

        $this->actingAs($user)->post(route('admin.events.reschedule', $event), [
            'date' => Carbon::now()->addDays(3)->format('Y-m-d'), 'start' => '14:00', 'end' => '16:00',
        ])->assertRedirect();

        $event->refresh();
        // La façon de diffuser le QR change (nouveau créneau) mais pas la racine
        // HMAC : les QR déjà imprimés/projetés restent valides après un report.
        $this->assertSame($secretAvant, $event->qr_secret, 'Le report ne doit pas régénérer le secret QR.');
        $this->assertSame($slugAvant, $event->public_slug, 'Le report ne doit pas changer l\'URL publique.');
    }

    public function test_le_report_conserve_les_presences_deja_enregistrees(): void
    {
        Mail::fake();
        $user = User::factory()->superAdmin()->create();
        $event = $this->event($this->filialeA, $this->type($this->filialeA));
        $attendance = $this->attend($event, 'visiteur@acs.ci');

        $this->actingAs($user)->post(route('admin.events.reschedule', $event), [
            'date' => Carbon::now()->addDays(3)->format('Y-m-d'), 'start' => '14:00', 'end' => '16:00',
        ])->assertRedirect();

        // La présence suit l'événement : ni supprimée, ni détachée.
        $this->assertSame($event->id, $attendance->refresh()->event_id);
        $this->assertSame(1, $event->attendances()->count());
        // Et l'invité/présent est notifié du nouveau créneau.
        Mail::assertQueued(EventRescheduledMail::class, 1);
    }

    // ======================================================================
    // Report PUIS transfert
    // ======================================================================

    public function test_report_puis_transfert_preserve_historique_et_reaffecte_le_type(): void
    {
        Mail::fake();
        $user = User::factory()->superAdmin()->create();
        $typeA = $this->type($this->filialeA);
        $typeB = $this->type($this->filialeB, 'Réunion');
        $event = $this->event($this->filialeA, $typeA);
        $attendance = $this->attend($event, 'visiteur@acs.ci');

        // 1) Report.
        $this->actingAs($user)->post(route('admin.events.reschedule', $event), [
            'date' => Carbon::now()->addDays(3)->format('Y-m-d'), 'start' => '14:00', 'end' => '16:00', 'reason' => 'Salle',
        ])->assertRedirect();
        $event->refresh();
        $this->assertSame(1, $event->reschedules()->count());

        // 2) Transfert vers la filiale B.
        app(EventTransferService::class)->transfer($event, $this->filialeB, $typeB, false, $user);

        $event->refresh();
        $this->assertSame($this->filialeB->id, $event->filiale_id);
        $this->assertSame($typeB->id, $event->event_type_id, 'Le type doit être valide dans la filiale cible.');
        // Le report reste tracé (event_reschedules suit via event_id).
        $this->assertSame(1, $event->reschedules()->count());
        // La présence suit toujours.
        $this->assertSame($event->id, $attendance->refresh()->event_id);
        // Le nouveau créneau (report) est conservé, non écrasé par le transfert.
        $this->assertSame('14:00', $event->starts_at->format('H:i'));
        $this->assertSame('16:00', $event->ends_at->format('H:i'));
    }

    // ======================================================================
    // Transfert PUIS report
    // ======================================================================

    public function test_transfert_puis_report_notifie_toujours_dans_la_filiale_cible(): void
    {
        Mail::fake();
        $user = User::factory()->superAdmin()->create();
        $typeA = $this->type($this->filialeA);
        $typeB = $this->type($this->filialeB, 'Réunion');
        $event = $this->event($this->filialeA, $typeA);
        $this->attend($event, 'visiteur@acs.ci');

        // 1) Transfert vers B.
        app(EventTransferService::class)->transfer($event, $this->filialeB, $typeB, false, $user);
        $event->refresh();

        // 2) Report après transfert : doit fonctionner et notifier la présence.
        $this->actingAs($user)->post(route('admin.events.reschedule', $event), [
            'date' => Carbon::now()->addDays(5)->format('Y-m-d'), 'start' => '09:00', 'end' => '10:00',
        ])->assertRedirect();

        $event->refresh();
        $this->assertSame($this->filialeB->id, $event->filiale_id, 'Le report ne doit pas ramener l\'événement dans la filiale d\'origine.');
        $this->assertSame($typeB->id, $event->event_type_id);
        $this->assertSame('09:00', $event->starts_at->format('H:i'));
        Mail::assertQueued(EventRescheduledMail::class, 1);
    }
}
