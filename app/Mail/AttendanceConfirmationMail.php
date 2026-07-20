<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Attendance;
use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Email de confirmation SIMPLE envoyé automatiquement à la clôture d'un événement
 * (décision produit : pas de pièce jointe, pas de récapitulatif détaillé).
 * Mis en file d'attente (traité par le cron sur hébergement mutualisé).
 */
class AttendanceConfirmationMail extends Mailable implements ShouldQueue
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
        return new Content(
            view: 'emails.attendance-confirmation',
            with: [
                'firstName' => $this->attendance->first_name,
                'eventTitle' => $this->attendance->event->title,
                'eventDate' => $this->attendance->event->starts_at->translatedFormat('j F Y à H:i'),
                'location' => $this->attendance->event->location,
                'reference' => $this->attendance->reference,
                'orgName' => Setting::branding()['org_name'],
            ],
        );
    }
}
