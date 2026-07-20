<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Enums\QrMode;
use App\Models\Event;
use App\Models\EventType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

    public function test_report_refuse_fin_avant_debut(): void
    {
        $event = $this->event();

        $this->actingAs($this->user)->post(route('admin.events.reschedule', $event), [
            'date' => Carbon::now()->addDay()->format('Y-m-d'), 'start' => '16:00', 'end' => '14:00',
        ])->assertSessionHasErrors('end');
    }
}
