<?php

use App\Http\Middleware\ApplyFilialeScope;
use App\Http\Middleware\EnsureActiveSession;
use App\Http\Middleware\EnsureUserRole;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Sentry\Laravel\Integration;

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

        // En-têtes de sécurité (CSP, HSTS, anti-clickjacking...) sur toute
        // réponse web, admin ET publique.
        $middleware->append(SecurityHeaders::class);

        // Prod tourne derrière le CDN Hostinger (hcdn) : sans ça, Request::ip()
        // renvoie l'IP du CDN pour TOUS les visiteurs, pas la leur. Conséquence
        // concrète : le rate-limit anti-fraude par IP (`attendance-store`,
        // 10/60s) deviendrait "10/60s pour tout l'événement" au lieu de par
        // visiteur — un vrai risque de blocage à tort pendant une ruée réelle.
        // `'*'` : l'origine ne reçoit du trafic QUE via ce CDN, donc faire
        // confiance à tout proxy en amont est le choix standard recommandé par
        // Laravel pour les plateformes hébergées dont l'IP du load balancer
        // n'est pas publiée (AWS ELB, Heroku, Hostinger...).
        $middleware->trustProxies(at: '*');

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

        // Sans DSN configuré (SENTRY_LARAVEL_DSN absent), le SDK ne fait
        // rien silencieusement — sûr à activer même avant que le compte
        // Sentry soit créé.
        Integration::handles($exceptions);
    })->create();
