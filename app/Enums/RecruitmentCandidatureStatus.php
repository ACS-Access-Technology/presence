<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Statut d'une candidature au test de recrutement en ligne.
 */
enum RecruitmentCandidatureStatus: string
{
    case EnCours = 'en_cours';
    case Soumis = 'soumis';
    case Expire = 'expire';
    case Abandonnee = 'abandonnee';

    public function label(): string
    {
        return match ($this) {
            self::EnCours => 'En cours',
            self::Soumis => 'Soumis',
            self::Expire => 'Expiré',
            self::Abandonnee => 'Abandonnée',
        };
    }

    public function estTerminee(): bool
    {
        return $this !== self::EnCours;
    }
}
