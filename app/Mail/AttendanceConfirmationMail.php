<?php

declare(strict_types=1);

namespace App\Mail;

use App\Jobs\SendAttendanceConfirmation;
use App\Models\Attendance;
use App\Support\Branding;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Email de confirmation SIMPLE envoyé automatiquement à la clôture d'un événement
 * (décision produit : pas de pièce jointe, pas de récapitulatif détaillé).
 *
 * Ce mailable n'est plus `ShouldQueue` : c'est désormais le job
 * {@see SendAttendanceConfirmation} qui porte la mise en file et l'envoie
 * en synchrone, afin de ne poser `confirmation_email_sent_at` qu'après un envoi
 * réellement réussi (audit cycle de vie email, bug #2).
 */
class AttendanceConfirmationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Attendance $attendance) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Confirmation de présence — '.$this->attendance->event->title,
        );
    }

    public function content(): Content
    {
        $event = $this->attendance->event;

        // Branding résolu depuis la filiale de L'ÉVÉNEMENT, jamais depuis une
        // session : ce mail part d'un job en file (aucune session admin active).
        $branding = Branding::forEvent($event);

        return new Content(
            view: 'emails.attendance-confirmation',
            with: [
                'firstName' => $this->attendance->first_name,
                'eventTitle' => $event->title,
                'eventDate' => $event->starts_at->translatedFormat('j F Y à H:i'),
                'location' => $event->location,
                'reference' => $this->attendance->reference,
                'feedbackUrl' => route('public.feedback.show', ['attendance' => $this->attendance->reference]),
                'orgName' => $branding->orgName,
                'accentColor' => $branding->accentColorOrDefault(),
            ],
        );
    }
}
