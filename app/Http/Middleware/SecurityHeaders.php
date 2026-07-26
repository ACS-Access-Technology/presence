<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * En-têtes de sécurité applicables à TOUTE réponse (défense en profondeur,
 * indépendante des contrôles applicatifs existants : cloisonnement filiale,
 * validation, rate-limit...).
 *
 * La CSP autorise `'unsafe-inline'` (script-src et style-src) car le front
 * de ce projet est volontairement du vanilla JS avec gestionnaires `onclick`
 * inline et blocs `<script>` de configuration (voir CLAUDE.md) — un CSP
 * strict à base de nonce demanderait de réécrire tout le front. Ça reste une
 * vraie protection : bloque le chargement de script/style depuis un domaine
 * tiers injecté (XSS avec `<script src="https://attaquant...">`), même si
 * une injection inline exploitant une faille applicative ne serait pas
 * bloquée par la CSP elle-même (les autres défenses — échappement HTML,
 * validation — restent la ligne de front pour ça).
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Géolocalisation nécessaire à l'émargement public (`self` uniquement,
        // jamais un tiers) ; caméra/micro jamais utilisés, refusés partout.
        $response->headers->set('Permissions-Policy', 'geolocation=(self), camera=(), microphone=()');

        $response->headers->set('Content-Security-Policy', implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data:",
            "font-src 'self'",
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'",
        ]));

        // HSTS uniquement sur une requête déjà en HTTPS : l'envoyer sur du
        // HTTP n'a pas de sens et pourrait verrouiller prématurément un
        // domaine dont le HTTPS n'est pas encore confirmé partout.
        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
