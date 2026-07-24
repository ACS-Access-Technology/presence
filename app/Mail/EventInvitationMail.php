<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\EventInvitation;
use App\Models\Setting;
use App\Support\IcsBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Notifie une personne invitée à un événement, avec un .ics joint (ajouter à
 * l'agenda) et un lien direct vers la page d'émargement. Une invitation N'EST
 * PAS une présence : ce mail ne fait qu'informer, l'invité doit quand même
 * scanner le QR et émarger le jour J.
 */
class EventInvitationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public EventInvitation $invitation) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Invitation — '.$this->invitation->event->title,
        );
    }

    public function content(): Content
    {
        $event = $this->invitation->event;

        return new Content(
            view: 'emails.event-invitation',
            with: [
                'firstName' => $this->invitation->person->first_name,
                'eventTitle' => $event->title,
                'eventDate' => $event->starts_at->translatedFormat('j F Y à H:i'),
                'location' => $event->location,
                'attendanceUrl' => route('public.attendance.show', ['event' => $event->public_slug]),
                'orgName' => Setting::branding()['org_name'],
            ],
        );
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [
            Attachment::fromData(
                fn () => IcsBuilder::forEvent($this->invitation->event),
                'evenement.ics',
            )->withMime('text/calendar'),
        ];
    }
}
