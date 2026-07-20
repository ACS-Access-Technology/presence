<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restreint l'accès à un ou plusieurs rôles. Ex. `->middleware('role:admin')`
 * pour les Paramètres (types, comptes, branding), réservés aux administrateurs.
 */
class EnsureUserRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null) {
            abort(403);
        }

        $allowed = array_map(
            static fn (string $role): UserRole => UserRole::from($role),
            $roles,
        );

        if (! in_array($user->role, $allowed, true)) {
            abort(403, "Accès réservé : rôle insuffisant.");
        }

        return $next($request);
    }
}
