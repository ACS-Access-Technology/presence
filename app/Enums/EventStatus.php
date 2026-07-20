<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Statut d'un événement, DÉRIVÉ (jamais stocké en colonne) de l'horloge serveur
 * et des marqueurs cancelled_at / closed_at. Voir App\Models\Event::status().
 */
enum EventStatus: string
{
    case AVenir = 'a_venir';
    case EnCours = 'en_cours';
    case Clos = 'clos';
    case Annule = 'annule';

    public function label(): string
    {
        return match ($this) {
            self::AVenir => 'À venir',
            self::EnCours => 'En cours',
            self::Clos => 'Clos',
            self::Annule => 'Annulé',
        };
    }
}
