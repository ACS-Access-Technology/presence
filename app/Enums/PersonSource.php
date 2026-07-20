<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Origine d'une fiche Personne dans le référentiel unifié.
 *
 * - Emargement : créée lors d'un émargement public (visiteur).
 * - Import     : importée en masse depuis le fichier « Personnel ACS Groupe » (is_staff = true).
 * - Manuel     : saisie manuellement par un organisateur.
 */
enum PersonSource: string
{
    case Emargement = 'emargement';
    case Import = 'import';
    case Manuel = 'manuel';

    public function label(): string
    {
        return match ($this) {
            self::Emargement => 'Émargement',
            self::Import => 'Import',
            self::Manuel => 'Saisie manuelle',
        };
    }
}
