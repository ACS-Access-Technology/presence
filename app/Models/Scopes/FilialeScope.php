<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Models\Event;
use App\Support\FilialeScoping;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global scope de cloisonnement par filiale, posé sur {@see Event}.
 *
 * Il ne filtre QUE lorsque le contexte {@see FilialeScoping} est actif (c.-à-d.
 * uniquement pendant une requête admin, via le middleware ApplyFilialeScope).
 * En dehors — cron, file d'attente, page publique, CLI, tests unitaires sans
 * requête HTTP — le contexte est inactif et ce scope est un no-op : toutes les
 * filiales sont visibles (RME-2). C'est le cœur de la garantie « le système ne
 * se scope jamais tout seul ».
 *
 * Le filtrage s'applique aussi automatiquement à la résolution de route-model
 * binding (`{event}`) : un événement d'une autre filiale devient introuvable
 * (404) au lieu de fuiter — y compris sur les URL d'export imbriquées.
 */
final class FilialeScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $scoping = app(FilialeScoping::class);

        if (! $scoping->isActive()) {
            return; // Contexte système/public/CLI : aucun filtre.
        }

        if ($scoping->shouldDenyAll()) {
            $builder->whereRaw('1 = 0'); // Fail-closed (RME-7).

            return;
        }

        $filialeId = $scoping->filialeId();

        if ($filialeId === null) {
            return; // SuperAdmin : voit tout, aucun filtre appliqué.
        }

        $builder->where($model->getTable().'.filiale_id', $filialeId);
    }
}
