<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\EventStatus;
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

/**
 * Lot E — QR post-clôture avec délai de grâce (15 min). Teste la borne exacte,
 * le découplage statut/émargement, la primauté de la clôture manuelle, et le
 * report de la clôture + email récap par le cron (RME-6).
 */
class GraceCheckInTest extends TestCase
{
    use RefreshDatabase;

    private EventType $type;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->type = EventType::create(['name' => 'Atelier', 'color' => '#7c3aed', 'position' => 0]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function event(Carbon $start, Carbon $end, bool $grace): Event
    {
        return Event::create([
            'title' => 'Atelier',
            'event_type_id' => $this->type->id,
            'starts_at' => $start,
            'ends_at' => $end,
            'grace_check_in_enabled' => $grace,
            'qr_mode' => QrMode::Statique->value,
            'qr_secret' => Str::random(32),
            'public_slug' => 'a-'.Str::random(5),
        ]);
    }

    // ---- checkInClosesAt ----

    public function test_borne_de_fermeture_sans_grace_egale_ends_at(): void
    {
        $end = Carbon::parse('2026-07-25 10:00:00');
        $event = $this->event($end->copy()->subHour(), $end, grace: false);

        $this->assertTrue($event->checkInClosesAt()->equalTo($end));
    }

    public function test_borne_de_fermeture_avec_grace_ajoute_15_min(): void
    {
        $end = Carbon::parse('2026-07-25 10:00:00');
        $event = $this->event($end->copy()->subHour(), $end, grace: true);

        $this->assertTrue($event->checkInClosesAt()->equalTo($end->copy()->addMinutes(15)));
    }

    // ---- isOpenForCheckIn : borne exacte ----

    public function test_sans_grace_ferme_juste_apres_ends_at(): void
    {
        // Heure à la seconde pleine : évite la troncature des microsecondes SQLite
        // qui fausserait la comparaison de borne exacte.
        $end = Carbon::parse('2026-07-25 10:00:00');
        $event = $this->event($end->copy()->subHour(), $end, grace: false);

        $this->assertTrue($event->isOpenForCheckIn($end));
        $this->assertFalse($event->isOpenForCheckIn($end->copy()->addSecond()));
    }

    public function test_avec_grace_ouvert_jusqua_15_min_pile_puis_ferme(): void
    {
        $end = Carbon::parse('2026-07-25 10:00:00');
        $event = $this->event($end->copy()->subHour(), $end, grace: true);

        $this->assertTrue($event->isOpenForCheckIn($end->copy()->addMinutes(14)));
        $this->assertTrue($event->isOpenForCheckIn($end->copy()->addMinutes(15)));           // borne incluse
        $this->assertFalse($event->isOpenForCheckIn($end->copy()->addMinutes(15)->addSecond())); // +1 s → refus
    }

    // ---- Découplage statut / émargement ----

    public function test_statut_reste_clos_pendant_la_grace(): void
    {
        $end = Carbon::now()->subMinutes(5);
        $event = $this->event($end->copy()->subHour(), $end, grace: true);

        // Émargement encore ouvert…
        $this->assertTrue($event->isOpenForCheckIn());
        // …mais le statut affiché est bien « Clos » (découplage, § 6.1).
        $this->assertSame(EventStatus::Clos, $event->status());
    }

    // ---- Clôture manuelle prime (Q-ME-7) ----

    public function test_cloture_manuelle_prime_sur_la_grace(): void
    {
        $end = Carbon::now()->subMinutes(5);
        $event = $this->event($end->copy()->subHour(), $end, grace: true);
        $event->update(['closed_at' => Carbon::now()]);

        $this->assertFalse($event->isOpenForCheckIn());
    }

    // ---- Cron : report clôture + email (RME-6) ----

    public function test_cron_ne_cloture_pas_un_evenement_en_grace(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-25 10:10:00'));
        // Terminé il y a 10 min, grâce active → borne effective à 10:15 > now.
        $event = $this->event(Carbon::parse('2026-07-25 09:00:00'), Carbon::parse('2026-07-25 10:00:00'), grace: true);
        $this->attend($event, 'awa@acs.ci');

        $this->artisan('events:close-due')->assertSuccessful();

        $this->assertNull($event->refresh()->closed_at);
        Mail::assertNothingSent();
    }

    public function test_cron_cloture_apres_la_grace_et_inclut_les_emargements(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-25 10:00:00'));
        $event = $this->event(Carbon::parse('2026-07-25 08:00:00'), Carbon::parse('2026-07-25 09:00:00'), grace: true);
        // Émargement pendant la grâce (09:10, avant la borne 09:15).
        Carbon::setTestNow(Carbon::parse('2026-07-25 09:10:00'));
        $this->attend($event, 'awa@acs.ci');

        // Juste avant la fin de grâce (09:14) : pas encore clos.
        Carbon::setTestNow(Carbon::parse('2026-07-25 09:14:00'));
        $this->artisan('events:close-due')->assertSuccessful();
        $this->assertNull($event->refresh()->closed_at);
        Mail::assertNothingSent();

        // Après la fin de grâce (09:16) : clôture + email incluant l'émargement.
        Carbon::setTestNow(Carbon::parse('2026-07-25 09:16:00'));
        $this->artisan('events:close-due')->assertSuccessful();
        $this->assertNotNull($event->refresh()->closed_at);
        Mail::assertSent(AttendanceConfirmationMail::class, 1);
    }

    public function test_cron_sans_grace_cloture_des_ends_at(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-25 10:01:00'));
        $event = $this->event(Carbon::parse('2026-07-25 09:00:00'), Carbon::parse('2026-07-25 10:00:00'), grace: false);

        $this->artisan('events:close-due')->assertSuccessful();

        $this->assertNotNull($event->refresh()->closed_at);
    }

    private function attend(Event $event, string $email): void
    {
        app(AttendanceService::class)->register($event, new AttendanceInput(
            email: $email, lastName: 'Koné', firstName: 'Awa',
            phone: '0', company: 'ACS', direction: 'SI', position: 'Analyste',
        ));
    }
}
