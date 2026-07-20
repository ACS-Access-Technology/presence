<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\AttendanceConfirmationMail;
use App\Models\Attendance;
use App\Models\Event;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Clôture les événements dont la fenêtre est dépassée et envoie à chaque personne
 * émargée un email de confirmation SIMPLE. Exécutée chaque minute par le scheduler
 * (cron cPanel unique). Idempotente : jamais de double clôture ni de double email.
 */
class CloseDueEvents extends Command
{
    protected $signature = 'events:close-due';

    protected $description = "Clôture les événements terminés et envoie les emails de confirmation.";

    public function handle(): int
    {
        $due = Event::query()
            ->whereNull('closed_at')
            ->whereNull('cancelled_at')
            ->where('ends_at', '<', Carbon::now())
            ->get();

        $closed = 0;
        $queued = 0;

        foreach ($due as $event) {
            $event->update(['closed_at' => Carbon::now()]);
            $closed++;

            $event->attendances()
                ->whereNull('confirmation_email_sent_at')
                ->with('person')
                ->chunkById(200, function ($attendances) use (&$queued): void {
                    /** @var Attendance $attendance */
                    foreach ($attendances as $attendance) {
                        $email = $attendance->person?->email;

                        // On n'envoie pas aux clés synthétiques (présences manuelles sans email).
                        if ($email !== null && ! Str::endsWith($email, '@presence.local')) {
                            Mail::to($email)->queue(new AttendanceConfirmationMail($attendance));
                            $queued++;
                        }

                        // Marqué même sans email : évite tout retraitement ultérieur.
                        $attendance->update(['confirmation_email_sent_at' => Carbon::now()]);
                    }
                });

            $event->update(['report_email_sent_at' => Carbon::now()]);
        }

        $this->info("{$closed} événement(s) clôturé(s), {$queued} email(s) de confirmation en file.");

        return self::SUCCESS;
    }
}
