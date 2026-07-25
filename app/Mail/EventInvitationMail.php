<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\EventInvitation;
use App\Support\Branding;
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
 * l'agenda). Une invitation N'EST PAS une présence : ce mail ne fait qu'informer,
 * l'invité doit scanner le QR affiché SUR PLACE et émarger le jour J.
 *
 * DÉCISION PRODUIT — aucun lien d'émargement direct dans l'email (les deux modes) :
 *  - Mode TOURNANT : le lien `/e/{slug}` sans le token de fenêtre HMAC (`?t=…`)
 *    affiche systématiquement « QR expiré » (le token change toutes les 15 s et ne
 *    peut pas être connu à l'avance) — un tel lien ne marcherait jamais et
 *    tromperait l'invité.
 *  - Mode STATIQUE : le lien fonctionnerait techniquement (aucun token requis),
 *    MAIS le diffuser par email revient à contourner l'anti-fraude « scan sur
 *    place » : un email est trivialement transférable, ce qui ouvre la porte à
 *    l'émargement par procuration (le géofencing éventuel ne protège pas si aucun
 *    périmètre n'est configuré, et transformerait de toute façon l'email en canal
 *    d'émargement, ce qu'une invitation n'est pas). Par cohérence entre les deux
 *    modes et pour ne pas entraîner l'utilisateur à « émarger depuis l'email »
 *    (habitude qui casse pour le mode tournant), on ne met AUCUN lien cliquable.
 *  L'invitation reste actionnable via le .ics joint ; l'émargement passe toujours
 *  par le scan du QR sur place. Si le client souhaite un lien de confort en mode
 *  statique, c'est un arbitrage produit à réactiver explicitement (géofencing
 *  obligatoire + avertissement), pas un défaut silencieux.
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

        // Branding résolu depuis la filiale de L'ÉVÉNEMENT, jamais depuis une
        // session : ce mail part d'un job en file (aucune session admin active).
        $branding = Branding::forEvent($event);

        return new Content(
            view: 'emails.event-invitation',
            with: [
                'firstName' => $this->invitation->person->first_name,
                'eventTitle' => $event->title,
                'eventDate' => $event->starts_at->translatedFormat('j F Y à H:i'),
                'location' => $event->location,
                'orgName' => $branding->orgName,
                'accentColor' => $branding->accentColorOrDefault(),
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
