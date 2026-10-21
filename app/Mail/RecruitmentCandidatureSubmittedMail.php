<?php

declare(strict_types=1);

namespace App\Mail;

use App\Enums\RecruitmentCandidatureStatus;
use App\Models\RecruitmentCandidature;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Notifie un manager (AdminFiliale de la filiale du poste, ou tout SuperAdmin)
 * qu'une candidature de recrutement est terminée (soumise, expirée ou abandonnée)
 * et notée.
 */
class RecruitmentCandidatureSubmittedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly RecruitmentCandidature $candidature) {}

    public function envelope(): Envelope
    {
        $sujet = match ($this->candidature->statut) {
            RecruitmentCandidatureStatus::Expire => 'Candidature expirée',
            RecruitmentCandidatureStatus::Abandonnee => 'Candidature abandonnée',
            default => 'Candidature reçue',
        };

        return new Envelope(
            subject: $sujet.' — '.$this->candidature->test->titre,
        );
    }

    /**
     * Phrase d'introduction propre à chaque issue : insérer le libellé du statut
     * (« Soumis », masculin capitalisé) dans une phrase sur « une candidature »
     * donnerait un français fautif et peu explicite pour l'abandon.
     */
    public function intro(): string
    {
        return match ($this->candidature->statut) {
            RecruitmentCandidatureStatus::Expire => "Le temps imparti s'est écoulé : une candidature a été clôturée automatiquement",
            RecruitmentCandidatureStatus::Abandonnee => 'Une candidature a été abandonnée (page du test rechargée ou quittée)',
            default => 'Une candidature vient d\'être soumise',
        };
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.recruitment.candidature-submitted',
            with: ['intro' => $this->intro()],
        );
    }
}
