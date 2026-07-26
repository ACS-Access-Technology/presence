<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\QrMode;
use App\Jobs\SendAttendanceConfirmation;
use App\Models\Attendance;
use App\Models\Event;
use App\Models\EventType;
use App\Models\Person;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event as EventFacade;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Cœur du bug #2 : « la base ne ment jamais ». Si l'envoi RÉEL échoue (SMTP down,
 * exception de rendu…), le job doit lever et NE PAS poser `confirmation_email_sent_at` ;
 * l'état reste diagnosticable (`queued_at` non nul + `sent_at` nul) et la file
 * pourra retenter sans doublon.
 *
 * Contrairement à SendAttendanceConfirmationTest (chemin nominal avec Mail::fake),
 * on n'utilise PAS Mail::fake ici : on laisse le vrai mailer (transport `array` en
 * test) rendre le message, et on force l'échec via un listener MessageSending qui
 * lève — ce qui interrompt l'envoi AVANT que le job ne marque `sent_at`.
 */
class SendAttendanceConfirmationFailureTest extends TestCase
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
            'event_id' => $event->id, 'person_id' => $person->id, 'reference' => 'PRS-'.Str::upper(Str::random(5)),
            'last_name' => 'Koné', 'first_name' => 'Awa', 'phone' => '0', 'company' => 'ACS',
            'direction' => 'SI', 'position' => 'QA', 'checked_in_at' => Carbon::now(),
            // Simule l'état posé par le cron : mis en file, pas encore envoyé.
            'confirmation_email_queued_at' => Carbon::now()->subMinute(),
            'confirmation_email_sent_at' => null,
        ]);
    }

    private function forceEnvoiEnEchec(): void
    {
        EventFacade::listen(MessageSending::class, function (): void {
            throw new \RuntimeException('SMTP indisponible');
        });
    }

    public function test_un_echec_denvoi_ne_pose_pas_sent_at_et_laisse_un_etat_coherent(): void
    {
        $attendance = $this->attendance('awa@acs.ci');
        $queuedBefore = $attendance->confirmation_email_queued_at;
        $this->forceEnvoiEnEchec();

        $exceptionLevee = false;
        try {
            (new SendAttendanceConfirmation($attendance->id))->handle();
        } catch (\Throwable $e) {
            $exceptionLevee = true;
        }

        $this->assertTrue($exceptionLevee, 'Le job doit propager l\'échec pour que la file retente.');

        $attendance->refresh();
        // Invariant « la base ne ment pas » : sent_at reste nul.
        $this->assertNull($attendance->confirmation_email_sent_at, 'sent_at ne doit JAMAIS être posé après un envoi raté.');
        // queued_at inchangé → l'incohérence est diagnosticable (queued non nul + sent nul).
        $this->assertTrue($queuedBefore->equalTo($attendance->confirmation_email_queued_at));
    }

    public function test_une_relance_apres_echec_envoie_une_seule_fois(): void
    {
        $attendance = $this->attendance('awa@acs.ci');

        // 1re tentative : échec.
        $this->forceEnvoiEnEchec();
        try {
            (new SendAttendanceConfirmation($attendance->id))->handle();
        } catch (\Throwable) {
        }
        $this->assertNull($attendance->refresh()->confirmation_email_sent_at);

        // 2e tentative : le SMTP est rétabli (on repart d'un dispatcher propre, sans
        // le listener qui levait). L'envoi réussit et pose sent_at, une seule fois.
        EventFacade::forget(MessageSending::class);
        $sent = 0;
        EventFacade::listen(MessageSending::class, function () use (&$sent): void {
            $sent++;
        });

        (new SendAttendanceConfirmation($attendance->id))->handle();

        $this->assertSame(1, $sent, 'Exactement un envoi à la relance.');
        $this->assertNotNull($attendance->refresh()->confirmation_email_sent_at);

        // 3e tentative (rejeu de file) : idempotent, aucun nouvel envoi.
        (new SendAttendanceConfirmation($attendance->id))->handle();
        $this->assertSame(1, $sent, 'Aucun renvoi une fois sent_at posé.');
    }
}
