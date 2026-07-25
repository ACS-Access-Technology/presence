<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\QrMode;
use App\Jobs\SendAttendanceConfirmation;
use App\Mail\AttendanceConfirmationMail;
use App\Models\Attendance;
use App\Models\Event;
use App\Models\EventType;
use App\Models\Person;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Le job d'envoi ne pose `confirmation_email_sent_at` qu'APRÈS un envoi réussi, et
 * reste idempotent (une seconde exécution ne renvoie pas). C'est la garantie
 * « la base ne ment pas » du bug #2.
 */
class SendAttendanceConfirmationTest extends TestCase
{
    use RefreshDatabase;

    private function attendance(string $email): Attendance
    {
        $type = EventType::create(['name' => 'Atelier', 'color' => '#7c3aed', 'position' => 0]);
        $event = Event::create([
            'title' => 'Atelier', 'event_type_id' => $type->id,
            'starts_at' => Carbon::now()->subHour(), 'ends_at' => Carbon::now(),
            'qr_mode' => QrMode::Statique->value, 'qr_secret' => Str::random(32), 'public_slug' => 'a-'.Str::random(5),
        ]);
        $person = Person::create(['email' => $email, 'last_name' => 'Koné', 'first_name' => 'Awa']);

        return Attendance::create([
            'event_id' => $event->id, 'person_id' => $person->id, 'reference' => 'PRS-'.Str::random(5),
            'last_name' => 'Koné', 'first_name' => 'Awa', 'phone' => '0', 'company' => 'ACS',
            'direction' => 'SI', 'position' => 'QA', 'checked_in_at' => Carbon::now(),
        ]);
    }

    public function test_envoie_et_marque_sent_apres_succes(): void
    {
        Mail::fake();
        $attendance = $this->attendance('awa@acs.ci');

        (new SendAttendanceConfirmation($attendance->id))->handle();

        Mail::assertSent(AttendanceConfirmationMail::class, 1);
        $this->assertNotNull($attendance->refresh()->confirmation_email_sent_at);
    }

    public function test_idempotent_ne_renvoie_pas_si_deja_envoye(): void
    {
        Mail::fake();
        $attendance = $this->attendance('awa@acs.ci');
        $attendance->forceFill(['confirmation_email_sent_at' => Carbon::now()->subMinute()])->save();

        (new SendAttendanceConfirmation($attendance->id))->handle();

        Mail::assertNothingSent();
    }

    public function test_ne_renvoie_pas_pour_une_cle_synthetique(): void
    {
        Mail::fake();
        $attendance = $this->attendance('manuel-xyz@presence.local');

        (new SendAttendanceConfirmation($attendance->id))->handle();

        Mail::assertNothingSent();
    }

    public function test_presence_supprimee_ne_casse_pas(): void
    {
        Mail::fake();

        (new SendAttendanceConfirmation(999999))->handle();

        Mail::assertNothingSent();
    }
}
