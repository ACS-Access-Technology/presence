<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Mode de diffusion du QR d'un événement, choisi à la création et verrouillé
 * dès qu'une présence existe.
 *
 * - Statique : QR stable imprimable (sans écran). Anti-fraude = géoloc + fenêtre + anti-doublon.
 * - Tournant : token régénéré toutes les 15 s, projeté en boucle, validé au scan.
 */
enum QrMode: string
{
    case Statique = 'statique';
    case Tournant = 'tournant';

    public function label(): string
    {
        return match ($this) {
            self::Statique => 'QR statique',
            self::Tournant => 'QR tournant',
        };
    }

    public function isTournant(): bool
    {
        return $this === self::Tournant;
    }
}
