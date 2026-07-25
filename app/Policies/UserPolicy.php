<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Http\Controllers\Admin\AccountController;
use App\Models\User;

/**
 * Autorisation sur la gestion des comptes (Lot D, T-ME-13).
 *
 * `User` n'est volontairement PAS couvert par le global scope de filiale (un
 * global scope sur User casserait les chargements de relations cross-filiale —
 * `creator`, `invited_by`, `recorded_by` — atteignables entre filiales, et
 * interférerait avec la requête `retrieveById` du provider d'auth). Le
 * cloisonnement de la surface comptes repose donc sur cette policy, appelée
 * explicitement dans {@see AccountController}, qui
 * ferme l'IDOR de la revue de sécurité (édition/suppression/reset d'un compte
 * d'une autre filiale).
 *
 * Fail-closed : un AdminFiliale sans `filiale_id` (incohérence, RME-7) ne gère
 * personne.
 */
final class UserPolicy
{
    /**
     * Gérer un compte cible (modifier rôle/statut, réinitialiser le mot de passe,
     * supprimer). SuperAdmin : tout compte. AdminFiliale : uniquement les
     * Organisateur de SA filiale (« gère les Organisateur de sa filiale »,
     * cadrage § 5.1) — il ne touche ni à un SuperAdmin ni à un autre AdminFiliale.
     */
    public function manage(User $actor, User $target): bool
    {
        if ($actor->isSuperAdmin()) {
            return true;
        }

        if (! $actor->isFilialeAdmin() || $actor->filiale_id === null) {
            return false;
        }

        return $target->role === UserRole::Organisateur
            && $target->filiale_id === $actor->filiale_id;
    }

    /** Réassigner un compte d'une filiale à une autre : SuperAdmin uniquement. */
    public function reassign(User $actor): bool
    {
        return $actor->isSuperAdmin();
    }
}
