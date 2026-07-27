<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
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
        // tout autre compte est borné à sa propre filiale. Fail-closed (RME-7,
        // voir ApplyFilialeScope::denyAll) : un compte non-SuperAdmin dont le
        // filiale_id est NULL (donnée incohérente) ne doit RIEN voir plutôt que
        // de tomber sur `WHERE filiale_id IS NULL`, qui exposerait les comptes
        // SuperAdmin (eux aussi à filiale_id NULL).
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
}
