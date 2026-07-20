<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PersonSource;
use App\Enums\QrMode;
use App\Models\Attendance;
use App\Models\Event;
use App\Models\EventType;
use App\Models\Person;
use App\Services\Attendance\AttendanceInput;
use App\Services\AttendanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class AttendanceServiceTest extends TestCase
{
    use RefreshDatabase;

    private AttendanceService $service;

    private EventType $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AttendanceService();
        $this->type = EventType::create(['name' => 'Atelier', 'color' => '#7c3aed', 'position' => 0]);
    }

    private function makeEvent(Carbon $start, Carbon $end): Event
    {
        return Event::create([
            'title' => 'Événement test',
            'event_type_id' => $this->type->id,
            'starts_at' => $start,
            'ends_at' => $end,
            'qr_mode' => QrMode::Tournant->value,
            'qr_secret' => Str::random(32),
            'public_slug' => Str::random(10),
        ]);
    }

    private function input(string $email = 'visiteur@exemple.ci'): AttendanceInput
    {
        return new AttendanceInput(
            email: $email,
            lastName: 'Koné',
            firstName: 'Awa',
            phone: '+225 07 00 00 00 00',
            company: 'ACS Consulting',
            direction: 'Direction SI',
            position: 'Analyste',
        );
    }

    public function test_enregistrement_cree_une_presence_et_une_personne(): void
    {
        $event = $this->makeEvent(Carbon::now()->subHour(), Carbon::now()->addHour());

        $attendance = $this->service->register($event, $this->input());

        $this->assertDatabaseCount('attendances', 1);
        $this->assertDatabaseCount('people', 1);
        $this->assertStringStartsWith('PRS-', $attendance->reference);
        $this->assertSame('ACS Consulting', $attendance->company); // snapshot figé
    }

    public function test_deuxieme_soumission_est_idempotente(): void
    {
        $event = $this->makeEvent(Carbon::now()->subHour(), Carbon::now()->addHour());

        $first = $this->service->register($event, $this->input());
        $second = $this->service->register($event, $this->input());

        $this->assertDatabaseCount('attendances', 1);
        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->reference, $second->reference);
    }

    public function test_detecte_un_chevauchement_actif_sur_un_autre_evenement(): void
    {
        $eventA = $this->makeEvent(Carbon::now()->subHour(), Carbon::now()->addHour());
        $eventB = $this->makeEvent(Carbon::now()->subMinutes(30), Carbon::now()->addHours(2));

        $this->service->register($eventA, $this->input('k.ndri@acs.ci'));
        $person = Person::where('email', 'k.ndri@acs.ci')->firstOrFail();

        $overlap = $this->service->activeOverlap($person, $eventB);

        $this->assertNotNull($overlap);
        $this->assertSame($eventA->id, $overlap->event_id);
    }

    public function test_confirmer_le_depart_cloture_la_presence_precedente(): void
    {
        $eventA = $this->makeEvent(Carbon::now()->subHour(), Carbon::now()->addHour());
        $eventB = $this->makeEvent(Carbon::now()->subMinutes(30), Carbon::now()->addHours(2));

        $onA = $this->service->register($eventA, $this->input('k.ndri@acs.ci'));
        $person = Person::where('email', 'k.ndri@acs.ci')->firstOrFail();
        $overlap = $this->service->activeOverlap($person, $eventB);

        $onB = $this->service->register($eventB, $this->input('k.ndri@acs.ci'), $overlap);

        $this->assertNotNull($onA->refresh()->departed_at);
        $this->assertNull($onB->departed_at);
        $this->assertDatabaseCount('attendances', 2);
    }

    public function test_recurrent_si_presence_sur_evenement_anterieur(): void
    {
        $past = $this->makeEvent(Carbon::now()->subDays(2), Carbon::now()->subDays(2)->addHours(2));
        $today = $this->makeEvent(Carbon::now()->subHour(), Carbon::now()->addHour());

        $this->service->register($past, $this->input('recurrent@exemple.ci'));
        $person = Person::where('email', 'recurrent@exemple.ci')->firstOrFail();

        $this->assertTrue($this->service->isRecurrent($person, $today));

        $newcomer = Person::create(['email' => 'nouveau@exemple.ci', 'last_name' => 'X', 'first_name' => 'Y']);
        $this->assertFalse($this->service->isRecurrent($newcomer, $today));
    }

    public function test_upsert_ne_degrade_pas_le_statut_personnel(): void
    {
        $staff = Person::create([
            'email' => 'staff@acsgroupe.ci', 'last_name' => 'Bamba', 'first_name' => 'Mariam',
            'is_staff' => true, 'source' => PersonSource::Import,
        ]);

        $event = $this->makeEvent(Carbon::now()->subHour(), Carbon::now()->addHour());
        $this->service->register($event, $this->input('staff@acsgroupe.ci'));

        $staff->refresh();
        $this->assertTrue($staff->is_staff);
        $this->assertSame(PersonSource::Import, $staff->source);
    }

    public function test_marquer_et_annuler_un_depart(): void
    {
        $event = $this->makeEvent(Carbon::now()->subHour(), Carbon::now()->addHour());
        $attendance = $this->service->register($event, $this->input());

        $this->service->markDeparture($attendance);
        $this->assertNotNull($attendance->refresh()->departed_at);

        $this->service->undoDeparture($attendance);
        $this->assertNull($attendance->refresh()->departed_at);
    }
}
