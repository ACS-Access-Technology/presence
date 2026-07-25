<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Models\EventType;
use App\Models\Scopes\FilialeScope;
use Closure;

/**
 * Règle de validation d'un `event_type_id` qui respecte le cloisonnement par
 * filiale.
 *
 * ⚠️ Pourquoi ne PAS utiliser `exists:event_types,id` : la règle `exists` exécute
 * une requête SQL BRUTE (DatabasePresenceVerifier), qui ignore le global scope
 * Eloquent {@see FilialeScope}. Un Organisateur/AdminFiliale
 * pouvait ainsi rattacher son événement à un type d'UNE AUTRE filiale par simple
 * énumération d'ID (fuite du nom/couleur du type via `Event::type()`, qui retire
 * volontairement le scope, + référence croisée inter-filiales). Cette règle passe
 * par une requête Eloquent scopée : en contexte admin, un type hors filiale est
 * invisible → validation refusée. SuperAdmin « Toutes » (scope no-op) : tout type
 * autorisé (transversal, légitime). Fail-closed pour un compte sans filiale.
 */
trait ValidatesEventTypeInScope
{
    protected function eventTypeInScopeRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            // whereKey applique le global scope de filiale (contexte admin actif).
            if (! EventType::whereKey($value)->exists()) {
                $fail("Le type d'événement est invalide.");
            }
        };
    }
}
