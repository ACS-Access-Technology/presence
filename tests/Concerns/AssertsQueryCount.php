<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Garde-fou anti-N+1 : compte les requêtes SQL exécutées pendant un callback.
 * Une page dont le nombre de requêtes croît avec le volume de données (au
 * lieu de rester constant) trahit une relation non eager-chargée.
 */
trait AssertsQueryCount
{
    private function countQueries(Closure $callback): int
    {
        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });

        $callback();

        return $count;
    }
}
