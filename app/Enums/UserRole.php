<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Rôles des comptes internes ACS Groupe (holding multi-filiales).
 *
 * Hiérarchie à trois niveaux (remplace l'ancien couple Admin|Organisateur et la
 * décision Q14 « pas de cloisonnement » — voir cadrage-multi-entites.md § 2) :
 *
 * - SuperAdmin    : le sommet de la holding. `filiale_id = NULL` (D-ME-6 : il
 *                   n'appartient à AUCUNE filiale, il est au-dessus). Voit et gère
 *                   toutes les filiales, tous les comptes, tous les événements ;
 *                   crée/supprime des filiales ; réassigne comptes et événements.
 * - AdminFiliale  : administrateur d'UNE filiale. Gère les Organisateur de sa
 *                   filiale et son paramétrage (types, branding). Ne voit que sa
 *                   filiale. `filiale_id` renseigné (contrainte applicative).
 * - Organisateur  : crée/gère les événements de SA filiale uniquement. Pas
 *                   d'accès aux Paramètres. `filiale_id` renseigné.
 *
 * ⚠️ L'ancienne valeur `admin` est migrée en base vers `super_admin` avec
 * `filiale_id = NULL` (migration add_filiale_id…). Aucun compte ne conserve
 * la valeur `admin` après migration ; ce case est donc retiré volontairement.
 */
enum UserRole: string
{
    case SuperAdmin = 'super_admin';
    case AdminFiliale = 'admin_filiale';
    case Organisateur = 'organisateur';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super administrateur',
            self::AdminFiliale => 'Administrateur de filiale',
            self::Organisateur => 'Organisateur',
        };
    }

    public function isSuperAdmin(): bool
    {
        return $this === self::SuperAdmin;
    }

    public function isFilialeAdmin(): bool
    {
        return $this === self::AdminFiliale;
    }

    /**
     * Accès aux Paramètres (types, comptes, branding) : ouvert au SuperAdmin
     * (global) et à l'AdminFiliale (scopé à sa filiale). Remplace l'ancien
     * `isAdmin()` qui gardait cette porte pour le seul rôle « admin ».
     */
    public function canManageSettings(): bool
    {
        return $this === self::SuperAdmin || $this === self::AdminFiliale;
    }
}
