<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Middleware\ApplyFilialeScope;
use App\Models\Scopes\FilialeScope;
use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * Contexte runtime du cloisonnement par filiale (arbitrage Q-ME-3, option A).
 *
 * Singleton résolu du conteneur. Il porte la décision « cette requête doit-elle
 * filtrer les données par filiale, et si oui laquelle ? ». Le {@see FilialeScope}
 * (global scope Eloquent posé sur Event) lit ce contexte à chaque requête.
 *
 * Pourquoi un contexte runtime plutôt qu'un scope basé directement sur `auth()` ?
 * → Pour garantir le NON-scoping des contextes système (RME-2). Le contexte est
 *   INACTIF par défaut ; seul le middleware {@see ApplyFilialeScope}
 *   (posé sur le groupe de routes `admin`) l'active. Ainsi le cron
 *   (`events:close-due`, `queue:work`) et la page publique `/e/{slug}`, qui ne
 *   passent jamais par ce middleware, voient TOUTES les filiales — sans dépendre
 *   d'un `withoutGlobalScope` que l'on pourrait oublier.
 *
 * Trois issues possibles une fois actif :
 *  - {@see scopeToAll()}      : aucun filtre (SuperAdmin, `filiale_id = NULL`, D-ME-6).
 *  - {@see scopeToFiliale()}  : filtre `filiale_id = <id>` (AdminFiliale / Organisateur).
 *  - {@see denyAll()}         : ne renvoie RIEN (fail-closed, RME-7) — cas d'un
 *                               compte non-SuperAdmin dont le `filiale_id` est NULL
 *                               par incohérence de données. On préfère ne rien
 *                               montrer plutôt que tout montrer.
 */
final class FilialeScoping
{
    private bool $active = false;

    private ?int $filialeId = null;

    private bool $denyAll = false;

    public function scopeToAll(): void
    {
        $this->active = true;
        $this->filialeId = null;
        $this->denyAll = false;
    }

    public function scopeToFiliale(int $filialeId): void
    {
        $this->active = true;
        $this->filialeId = $filialeId;
        $this->denyAll = false;
    }

    public function denyAll(): void
    {
        $this->active = true;
        $this->filialeId = null;
        $this->denyAll = true;
    }

    public function disable(): void
    {
        $this->active = false;
        $this->filialeId = null;
        $this->denyAll = false;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function shouldDenyAll(): bool
    {
        return $this->denyAll;
    }

    public function filialeId(): ?int
    {
        return $this->filialeId;
    }

    /**
     * Applique la même décision de cloisonnement à une requête sur un modèle NON
     * couvert par le global scope {@see FilialeScope} (typiquement {@see User},
     * volontairement hors global scope — voir la note d'arbitrage du contrôleur).
     *
     * Reproduit fidèlement la logique du global scope :
     *  - contexte inactif (cron / public / CLI)         → aucun filtre ;
     *  - denyAll (non-SuperAdmin sans filiale, RME-7)    → ne renvoie rien ;
     *  - SuperAdmin « Toutes les filiales » (id null)    → aucun filtre ;
     *  - filiale ciblée                                  → `where <colonne> = <id>`.
     *
     * @template TBuilder of Builder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public function constrain(Builder $query, string $column = 'filiale_id'): Builder
    {
        if (! $this->active) {
            return $query;
        }

        if ($this->denyAll) {
            return $query->whereRaw('1 = 0');
        }

        if ($this->filialeId === null) {
            return $query;
        }

        return $query->where($column, $this->filialeId);
    }
}
