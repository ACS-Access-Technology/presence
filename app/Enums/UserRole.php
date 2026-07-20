<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Rôles des comptes internes ACS Groupe.
 *
 * - Admin        : tout gérer, y compris Paramètres (types, comptes, branding).
 * - Organisateur : crée/gère événements, présences et comptes-rendus, mais
 *                  pas d'accès aux Paramètres/Comptes/Types.
 *
 * Pas de cloisonnement entre organisateurs (Q14) : tout compte voit tous les événements.
 */
enum UserRole: string
{
    case Admin = 'admin';
    case Organisateur = 'organisateur';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrateur',
            self::Organisateur => 'Organisateur',
        };
    }

    public function isAdmin(): bool
    {
        return $this === self::Admin;
    }
}
