<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ApplyFilialeScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Sélecteur de contexte filiale du SuperAdmin (Q-ME-6). Persiste en session la
 * filiale d'affichage choisie dans la topbar ; « Toutes les filiales » = pas de
 * sélection. Réservé au SuperAdmin (route `role:super_admin`) : les autres rôles
 * ont un contexte figé et n'ont pas besoin de ce point d'entrée.
 *
 * {@see ApplyFilialeScope} relit cette valeur à chaque requête admin pour décider
 * du périmètre de scoping.
 */
class FilialeContextController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        // Une filiale DÉSACTIVÉE ne peut pas être choisie comme périmètre : la règle
        // `exists` est scopée sur `is_active = true`, donc un id inactif échoue avec
        // un message clair plutôt qu'un repli silencieux sur « Toutes » (anomalie P1).
        $data = $request->validate([
            'filiale_id' => ['nullable', 'integer', Rule::exists('filiales', 'id')->where('is_active', true)],
            'redirect_to' => ['nullable', 'in:dashboard,settings'],
        ], [
            'filiale_id.exists' => 'Cette filiale est introuvable ou désactivée : elle ne peut pas être sélectionnée comme périmètre.',
        ]);

        if (empty($data['filiale_id'])) {
            $request->session()->forget(ApplyFilialeScope::CONTEXT_SESSION_KEY);
        } else {
            $request->session()->put(ApplyFilialeScope::CONTEXT_SESSION_KEY, (int) $data['filiale_id']);
        }

        // Raccourcis depuis la gestion des filiales : basculent le contexte ET
        // amènent directement sur le périmètre scopé, plutôt que de revenir sur
        // la page des filiales (comportement du sélecteur topbar, inchangé).
        if (($data['redirect_to'] ?? null) === 'dashboard') {
            return redirect()->route('admin.dashboard');
        }

        // Après création d'une filiale : droit sur Comptes, avec l'invitation du
        // premier admin déjà ouverte (branding se règle depuis l'onglet Général).
        if (($data['redirect_to'] ?? null) === 'settings') {
            return redirect()->route('admin.settings.index', ['tab' => 'comptes', 'invite' => 1]);
        }

        return back();
    }
}
