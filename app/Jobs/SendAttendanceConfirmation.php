<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Console\Commands\CloseDueEvents;
use App\Mail\AttendanceConfirmationMail;
use App\Models\Attendance;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * Job d'envoi de l'email de confirmation de présence.
 *
 * Il encapsule le mailable {@see AttendanceConfirmationMail} pour distinguer
 * proprement « mise en file » et « envoi réellement confirmé » (audit cycle de vie
 * email, bug #2) :
 * - le cron ({@see CloseDueEvents}) pose
 *   `confirmation_email_queued_at` puis DISPATCHE ce job ;
 * - c'est CE job qui pose `confirmation_email_sent_at`, mais uniquement APRÈS que
 *   le `send()` a réellement réussi. Si l'envoi échoue, la colonne reste nulle :
 *   la base ne ment jamais, et l'échec reste diagnosticable
 *   (`confirmation_email_queued_at` non nul + `confirmation_email_sent_at` nul).
 *
 * On sérialise l'ID (pas le modèle) : le job re-charge une présence fraîche à
 * l'exécution et reste idempotent (une seconde exécution ne renvoie pas).
 */
final class SendAttendanceConfirmation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $attendanceId) {}

    public function handle(): void
    {
        $attendance = Attendance::query()->with(['person', 'event'])->find($this->attendanceId);

        // Présence supprimée entre la mise en file et l'exécution : rien à faire.
        if ($attendance === null) {
            return;
        }

        // Idempotence : déjà réellement envoyé lors d'un essai précédent.
        if ($attendance->confirmation_email_sent_at !== null) {
            return;
        }

        $email = $attendance->person?->email;

        // Garde défensive : le cron ne dispatche pas les clés synthétiques
        // (@presence.local, présences manuelles sans vrai email), mais on re-vérifie
        // ici pour ne jamais tenter un envoi invalide si la donnée a changé.
        if ($email === null || str_ends_with($email, '@presence.local')) {
            return;
        }

        Mail::to($email)->send(new AttendanceConfirmationMail($attendance));

        // Marqué SEULEMENT après un send() réussi. Si le send() lève, on n'arrive
        // jamais ici : le job échoue et sera retenté par la file (aucun mensonge).
        $attendance->forceFill(['confirmation_email_sent_at' => Carbon::now()])->save();
    }
}
