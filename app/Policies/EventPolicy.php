<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Event;
use App\Models\Scopes\FilialeScope;
use App\Models\User;

/**
 * Autorisation par filiale sur les événements (T-ME-06, défense en profondeur).
 *
 * Le global scope {@see FilialeScope} empêche déjà de VOIR un
 * événement d'une autre filiale (résolution de route → 404). Cette policy est la
 * seconde barrière (RME-1) : à appeler explicitement (`$this->authorize(...)`,
 * middleware `can:`) sur les actions d'écriture/lecture sensibles lors du Lot C.
 * Elle renvoie 403 sur un accès croisé filiale A→B, et TOUJOURS vrai pour le
 * SuperAdmin (transversal).
 *
 * Auto-découverte Laravel : App\Policies\EventPolicy ↔ App\Models\Event.
 */
final class EventPolicy
{
    public function view(User $user, Event $event): bool
    {
        return $this->sameFiliale($user, $event);
    }

    public function update(User $user, Event $event): bool
    {
        return $this->sameFiliale($user, $event);
    }

    public function export(User $user, Event $event): bool
    {
        return $this->sameFiliale($user, $event);
    }

    public function delete(User $user, Event $event): bool
    {
        return $this->sameFiliale($user, $event);
    }

    /**
     * Transférer un événement (ou sa série) vers une autre filiale : SuperAdmin
     * uniquement (T-ME-14, comme la réassignation de compte). Opération
     * transverse par nature — un AdminFiliale/Organisateur ne déplace jamais un
     * événement hors de sa filiale.
     */
    public function transfer(User $user, Event $event): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * Un compte n'accède qu'aux événements de SA filiale ; le SuperAdmin accède à
     * tout. Fail-closed : un non-SuperAdmin sans filiale (`filiale_id` NULL par
     * incohérence) n'accède à rien (RME-7).
     */
    private function sameFiliale(User $user, Event $event): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->filiale_id !== null && $user->filiale_id === $event->filiale_id;
    }
}
