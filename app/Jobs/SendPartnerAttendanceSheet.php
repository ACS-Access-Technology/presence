<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Console\Commands\CloseDueEvents;
use App\Mail\PartnerAttendanceSheetMail;
use App\Models\Event;
use App\Models\Scopes\FilialeScope;
use App\Services\AttendanceSheetPdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * Envoie la feuille d'émargement PDF (à l'en-tête du partenaire) au destinataire
 * choisi à la création de la rencontre. Mis en file par {@see CloseDueEvents} à
 * la clôture, dans la même transaction que le marqueur de batch.
 *
 * Même contrat que {@see SendAttendanceConfirmation} : `partner_sheet_sent_at`
 * n'est posé qu'APRÈS un `send()` réussi (la base ne ment jamais) et le job est
 * idempotent (une seconde exécution ne renvoie pas). Le PDF est généré à
 * l'exécution, donc il reflète toutes les présences au moment de l'envoi.
 */
final class SendPartnerAttendanceSheet implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $eventId) {}

    public function handle(AttendanceSheetPdf $sheet): void
    {
        $event = Event::query()->withoutGlobalScope(FilialeScope::class)
            ->with('partner')->find($this->eventId);

        if ($event === null
            || $event->partner_sheet_sent_at !== null
            || $event->cancelled_at !== null
            || $event->partner === null
            || $event->partner_recipient_email === null) {
            return;
        }

        Mail::to($event->partner_recipient_email, $event->partner_recipient_name)
            ->send(new PartnerAttendanceSheetMail($event, $sheet->renderAll($event)));

        $event->forceFill(['partner_sheet_sent_at' => Carbon::now()])->save();
    }
}
