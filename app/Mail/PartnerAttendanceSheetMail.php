<?php

declare(strict_types=1);

namespace App\Mail;

use App\Jobs\SendPartnerAttendanceSheet;
use App\Models\Event;
use App\Support\Branding;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Feuille d'émargement d'une rencontre partenaire, envoyée au destinataire choisi
 * à la création. Aux couleurs du partenaire, sans mention de l'outil (marque
 * blanche). Envoyé de façon synchrone depuis {@see SendPartnerAttendanceSheet}
 * (lui-même en file) : pas de ShouldQueue ici, sinon le PDF serait sérialisé dans
 * la file `database`.
 */
class PartnerAttendanceSheetMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Event $event, private readonly string $pdf) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Liste d'émargement — ".$this->event->title,
        );
    }

    public function content(): Content
    {
        $branding = Branding::forEvent($this->event);

        return new Content(
            view: 'emails.partner-attendance-sheet',
            with: [
                'recipientName' => $this->event->partner_recipient_name,
                'eventTitle' => $this->event->title,
                'eventDate' => $this->event->starts_at->translatedFormat('j F Y'),
                'location' => $this->event->location,
                'count' => $this->event->attendances()->count(),
                'orgName' => $branding->orgName,
                'accentColor' => $branding->accentColorOrDefault(),
            ],
        );
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn (): string => $this->pdf, 'emargement-'.$this->event->public_slug.'.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
