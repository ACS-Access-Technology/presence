<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAgentDirectoryRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Annuaire du personnel interne, cloisonné par filiale.
 *
 * L'agent est un compte User enrichi (phone/direction/service/position).
 * Cloisonnement explicite : User n'est pas sous global scope de filiale
 * (voir ApplyFilialeScope, qui ne couvre que les entités métier).
 */
final class AgentDirectoryController extends Controller
{
    public function index(Request $request): View
    {
        $actor = $request->user();

        $query = User::query()
            ->with('filiale')
            ->orderBy('name');

        // Le SuperAdmin (filiale_id NULL, au-dessus des filiales) voit tout ;
        // tout autre compte est borné à sa propre filiale. Fail-closed (cf.
        // FilialeScoping::denyAll / le global scope FilialeScope, doctrine RME-7) :
        // un compte non-SuperAdmin dont le filiale_id est NULL (donnée incohérente)
        // ne doit RIEN voir plutôt que de tomber sur `WHERE filiale_id IS NULL`,
        // qui exposerait les comptes SuperAdmin (eux aussi à filiale_id NULL).
        if (! $actor->isSuperAdmin()) {
            if ($actor->filiale_id === null) {
                $query->whereRaw('1 = 0');
            } else {
                $query->where('filiale_id', $actor->filiale_id);
            }
        }

        return view('admin.annuaire.index', [
            'agents' => $query->get(),
        ]);
    }

    public function update(UpdateAgentDirectoryRequest $request, User $agent): RedirectResponse
    {
        // Un AdminFiliale ne peut éditer qu'un agent de sa propre filiale.
        // Fail-closed (même logique que index()) : `users.filiale_id` est en
        // `nullOnDelete()` sur la FK filiale — un AdminFiliale/Agent orphelin
        // après suppression de sa filiale se retrouve avec filiale_id NULL. Sans
        // ce garde explicite, la comparaison `!==` laisserait passer un tel
        // compte contre N'IMPORTE QUEL autre compte à filiale_id NULL, y compris
        // un SuperAdmin (lui aussi à filiale_id NULL par conception).
        $actor = $request->user();
        if (! $actor->isSuperAdmin() && ($actor->filiale_id === null || $agent->filiale_id !== $actor->filiale_id)) {
            abort(403);
        }

        $agent->update($request->validated());

        return redirect()->route('admin.annuaire.index')
            ->with('status', 'Fiche annuaire mise à jour.');
    }
}
