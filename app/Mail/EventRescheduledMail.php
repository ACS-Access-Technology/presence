<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\EventReschedule;
use App\Models\Person;
use App\Support\Branding;
use App\Support\IcsBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Notifie une personne (invitée ou déjà présente) qu'un événement a été reporté :
 * rappelle l'ancien créneau, annonce le nouveau et joint un .ics de mise à jour
 * (même UID que l'invitation initiale, `SEQUENCE` incrémenté, `METHOD:REQUEST`)
 * pour que l'entrée d'agenda existante soit corrigée sans doublon.
 *
 * On s'appuie sur le {@see EventReschedule} plutôt que sur l'événement « vivant » :
 * il porte un SNAPSHOT immuable de l'ancien et du nouveau créneau de CE report,
 * donc le mail reste cohérent même si l'événement est reporté à nouveau ensuite.
 */
class EventRescheduledMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Person $person,
        public EventReschedule $reschedule,
        public int $sequence,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Report — '.$this->reschedule->event->title,
        );
    }

    public function content(): Content
    {
        $event = $this->reschedule->event;

        // Branding résolu depuis la filiale de L'ÉVÉNEMENT, jamais depuis une
        // session : ce mail part d'un job en file (aucune session admin active).
        $branding = Branding::forEvent($event);

        return new Content(
            view: 'emails.event-reschedule',
            with: [
                'firstName' => $this->person->first_name,
                'eventTitle' => $event->title,
                'oldSchedule' => self::formatSchedule($this->reschedule->old_starts_at, $this->reschedule->old_ends_at),
                'newSchedule' => self::formatSchedule($this->reschedule->new_starts_at, $this->reschedule->new_ends_at),
                'location' => $event->location,
                'reason' => $this->reschedule->reason,
                'orgName' => $branding->orgName,
                'accentColor' => $branding->accentColorOrDefault(),
            ],
        );
    }

    /** Créneau lisible : « 27 juillet 2026 · 14:00–16:00 ». */
    private static function formatSchedule(Carbon $start, Carbon $end): string
    {
        return $start->translatedFormat('j F Y').' · '.$start->format('H:i').'–'.$end->format('H:i');
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [
            Attachment::fromData(
                fn () => IcsBuilder::rescheduleForEvent(
                    $this->reschedule->event,
                    $this->reschedule->new_starts_at,
                    $this->reschedule->new_ends_at,
                    $this->sequence,
                    $this->person->email,
                ),
                'evenement.ics',
            )->withMime('text/calendar'),
        ];
    }
}
