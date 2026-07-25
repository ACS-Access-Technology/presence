<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Révocation de session « à chaud » : reverifie l'état ACTIF du compte ET de sa
 * filiale à CHAQUE requête authentifiée du groupe admin.
 *
 * Sans ce middleware, `LoginRequest` ne vérifiait `is_active` (compte + filiale)
 * qu'À LA CONNEXION : une session déjà ouverte survivait à une désactivation
 * faite entre-temps par un SuperAdmin/AdminFiliale (anomalie ÉLEVÉ). Le compte
 * gardait l'accès à toute la plateforme jusqu'à sa prochaine reconnexion.
 *
 * Posé sur le groupe `admin` (routes/web.php), JUSTE AVANT {@see ApplyFilialeScope}
 * dans l'ordre de priorité (voir bootstrap/app.php) : inutile d'activer un
 * scoping ni de résoudre un route-model binding {event} pour un utilisateur qu'on
 * s'apprête à éjecter. Comme `ApplyFilialeScope`, il ne s'exécute JAMAIS pour le
 * cron, la file d'attente ni la page publique `/e/{slug}` — ces contextes n'ont
 * pas d'utilisateur authentifié.
 *
 * Le SuperAdmin (filiale_id NULL, D-ME-6) n'est jamais concerné par la
 * vérification filiale : il n'en a pas.
 */
final class EnsureActiveSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Requête sans utilisateur (ne devrait pas arriver derrière `auth`, mais
        // fail-safe) : rien à révoquer, on laisse passer.
        if (! $user instanceof User) {
            return $next($request);
        }

        if (! $user->is_active) {
            return $this->revoke($request, 'Votre compte a été désactivé.');
        }

        // Un compte rattaché (AdminFiliale / Organisateur) dont la filiale a été
        // désactivée perd l'accès. Le SuperAdmin (sans filiale) est hors périmètre.
        if (! $user->isSuperAdmin()
            && $user->filiale !== null
            && ! $user->filiale->is_active) {
            return $this->revoke($request, 'Votre filiale a été désactivée.');
        }

        return $next($request);
    }

    /**
     * Déconnecte le compte, invalide la session et régénère le jeton CSRF, puis
     * renvoie vers la connexion. Distinction JSON/page identique à la politique
     * `shouldRenderJsonWhen` du projet (bootstrap/app.php) : un fetch/AJAX en
     * cours côté client reçoit une réponse 401 exploitable (avec la cible de
     * redirection) plutôt qu'une page HTML de connexion qui casserait l'appel.
     */
    private function revoke(Request $request, string $message): JsonResponse|RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'redirect' => route('login'),
            ], 401);
        }

        // Le message est porté par la clé `email` : c'est celle qu'affiche l'alerte
        // de la page de connexion (resources/views/auth/login.blade.php), donc le
        // motif de l'éjection est visible sans modifier la vue.
        return redirect()->route('login')->withErrors(['email' => $message]);
    }
}
