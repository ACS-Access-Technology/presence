<?php

use App\Http\Middleware\ApplyFilialeScope;
use App\Http\Middleware\EnsureActiveSession;
use App\Http\Middleware\EnsureUserRole;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => EnsureUserRole::class,
            'filiale.scope' => ApplyFilialeScope::class,
            'session.active' => EnsureActiveSession::class,
        ]);

        // Le cloisonnement doit être actif AVANT la résolution du route-model
        // binding (`{event}`), sinon un événement d'une autre filiale serait
        // résolu hors scope et fuiterait (200 au lieu de 404). On force donc
        // ApplyFilialeScope juste avant SubstituteBindings dans l'ordre de priorité.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: ApplyFilialeScope::class,
        );

        // La révocation de session (compte/filiale désactivé) est évaluée JUSTE
        // AVANT le scoping : inutile de scoper ou de résoudre un {event} pour un
        // compte qu'on s'apprête à éjecter. Ordre final : auth → EnsureActiveSession
        // → ApplyFilialeScope → SubstituteBindings.
        $middleware->prependToPriorityList(
            before: ApplyFilialeScope::class,
            prepend: EnsureActiveSession::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Rendre les erreurs (dont la validation) en JSON dès que la requête
        // l'attend — les endpoints publics d'émargement sont appelés en fetch JSON.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
