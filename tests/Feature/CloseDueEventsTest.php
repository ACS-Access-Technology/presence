<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\QrMode;
use App\Mail\AttendanceConfirmationMail;
use App\Models\Event;
use App\Models\EventType;
use App\Services\Attendance\AttendanceInput;
use App\Services\AttendanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class CloseDueEventsTest extends TestCase
{
    use RefreshDatabase;

    private EventType $type;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->type = EventType::create(['name' => 'Atelier', 'color' => '#7c3aed', 'position' => 0]);
    }

    private function event(Carbon $start, Carbon $end): Event
    {
        return Event::create([
            'title' => 'Atelier', 'event_type_id' => $this->type->id,
            'starts_at' => $start, 'ends_at' => $end,
            'qr_mode' => QrMode::Statique->value, 'qr_secret' => Str::random(32), 'public_slug' => 'a-'.Str::random(5),
        ]);
    }

    private function attend(Event $e, string $email, bool $manual = false): void
    {
        app(AttendanceService::class)->register($e, new AttendanceInput(
            email: $email, lastName: 'Koné', firstName: 'Awa',
            phone: '0', company: 'ACS', direction: 'SI', position: 'Analyste', isManual: $manual, manualConfirmed: $manual,
        ));
    }

    public function test_cloture_les_evenements_termines_et_notifie(): void
    {
        $event = $this->event(Carbon::now()->subHours(3), Carbon::now()->subHour());
        $this->attend($event, 'awa@acs.ci');
        $this->attend($event, 'k.ndri@orange.ci');
        $this->attend($event, 'manuel-abc@presence.local', manual: true); // sans vrai email

        $this->artisan('events:close-due')->assertSuccessful();

        $event->refresh();
        $this->assertNotNull($event->closed_at);
        $this->assertNotNull($event->report_email_sent_at);

        // 2 emails réels en file, pas pour la clé synthétique.
        Mail::assertQueued(AttendanceConfirmationMail::class, 2);
        Mail::assertNotQueued(AttendanceConfirmationMail::class, fn ($m) => $m->hasTo('manuel-abc@presence.local'));

        $this->assertSame(0, $event->attendances()->whereNull('confirmation_email_sent_at')->count());
    }

    public function test_ne_notifie_jamais_deux_fois(): void
    {
        $event = $this->event(Carbon::now()->subHours(3), Carbon::now()->subHour());
        $this->attend($event, 'awa@acs.ci');

        $this->artisan('events:close-due')->assertSuccessful();
        $this->artisan('events:close-due')->assertSuccessful();

        Mail::assertQueued(AttendanceConfirmationMail::class, 1);
    }

    public function test_evenement_annule_nest_pas_cloture(): void
    {
        $event = $this->event(Carbon::now()->subHours(3), Carbon::now()->subHour());
        $event->update(['cancelled_at' => Carbon::now()->subDay()]);
        $this->attend($event, 'awa@acs.ci');

        $this->artisan('events:close-due')->assertSuccessful();

        $this->assertNull($event->refresh()->closed_at);
        Mail::assertNothingQueued();
    }

    public function test_evenement_en_cours_ou_futur_ignore(): void
    {
        $this->event(Carbon::now()->subHour(), Carbon::now()->addHour());   // en cours
        $this->event(Carbon::now()->addDay(), Carbon::now()->addDay()->addHour()); // futur

        $this->artisan('events:close-due')->assertSuccessful();

        $this->assertSame(0, Event::whereNotNull('closed_at')->count());
        Mail::assertNothingQueued();
    }
}
