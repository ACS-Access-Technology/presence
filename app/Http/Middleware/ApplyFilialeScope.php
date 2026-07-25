<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Filiale;
use App\Models\User;
use App\Support\FilialeScoping;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Active le cloisonnement par filiale pour la durée d'une requête admin.
 *
 * Posé sur le groupe de routes `admin` (routes/web.php). C'est le SEUL endroit
 * qui active le contexte {@see FilialeScoping} : le cron, la file d'attente et la
 * page publique `/e/{slug}` ne passent jamais par ici et restent donc non scopés
 * (RME-2 — prouvé par test).
 *
 * Traduction rôle → décision de scoping :
 *  - SuperAdmin (filiale_id NULL)             → « Toutes les filiales » (scopeToAll)
 *    par défaut, OU la filiale choisie via le sélecteur de topbar (Q-ME-6),
 *    persistée en session (`filiale_context_id`).
 *  - AdminFiliale / Organisateur (filiale_id) → sa filiale uniquement (contexte
 *    figé, pas de bascule).
 *  - Non-SuperAdmin SANS filiale_id            → fail-closed (denyAll, RME-7) :
 *    donnée incohérente, on ne montre rien plutôt que tout.
 */
final class ApplyFilialeScope
{
    /** Clé de session portant la filiale sélectionnée par un SuperAdmin. */
    public const string CONTEXT_SESSION_KEY = 'filiale_context_id';

    /**
     * Clé de message flash posée quand le contexte en session pointait vers une
     * filiale devenue invalide (désactivée/supprimée) et a été replié sur « Toutes ».
     * Affichée une seule fois (via `session()->now()`) par le layout admin.
     */
    public const string CONTEXT_WARNING_KEY = 'filiale_context_warning';

    public function __construct(private readonly FilialeScoping $scoping) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            if ($user->isSuperAdmin()) {
                $this->applySuperAdminContext($request);
            } elseif ($user->filiale_id !== null) {
                $this->scoping->scopeToFiliale($user->filiale_id);
            } else {
                $this->scoping->denyAll();
            }
        }

        return $next($request);
    }

    /**
     * SuperAdmin : « Toutes les filiales » par défaut, ou la filiale choisie si
     * elle existe encore et est active.
     *
     * Arbitrage « filiale désactivée en session » (anomalie P1) : plutôt que de
     * bloquer le SuperAdmin (ce qui l'enfermerait si un autre SuperAdmin désactive
     * sa filiale de contexte), on REPLIE explicitement sur « Toutes » ET on pose un
     * avertissement visible (non silencieux) affiché une fois. La sélection
     * invalide est purgée pour ne pas répéter l'avertissement à chaque requête.
     */
    private function applySuperAdminContext(Request $request): void
    {
        $selectedId = $request->session()->get(self::CONTEXT_SESSION_KEY);

        if ($selectedId === null) {
            $this->scoping->scopeToAll();

            return;
        }

        $exists = Filiale::query()
            ->whereKey($selectedId)
            ->where('is_active', true)
            ->exists();

        if ($exists) {
            $this->scoping->scopeToFiliale((int) $selectedId);
        } else {
            $request->session()->forget(self::CONTEXT_SESSION_KEY);
            $request->session()->now(
                self::CONTEXT_WARNING_KEY,
                'La filiale précédemment sélectionnée a été désactivée ou supprimée. '
                .'Le périmètre est revenu à « Toutes les filiales ».',
            );
            $this->scoping->scopeToAll();
        }
    }
}
