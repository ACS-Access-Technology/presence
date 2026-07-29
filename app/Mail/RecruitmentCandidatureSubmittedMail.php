<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\RecruitmentCandidature;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Notifie un manager (AdminFiliale de la filiale du poste, ou tout SuperAdmin)
 * qu'une candidature de recrutement est terminée (soumise ou expirée) et notée.
 */
class RecruitmentCandidatureSubmittedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly RecruitmentCandidature $candidature) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Candidature reçue — '.$this->candidature->test->titre,
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.recruitment.candidature-submitted');
    }
}
