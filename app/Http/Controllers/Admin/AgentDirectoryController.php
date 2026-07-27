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
        // tout autre compte est borné à sa propre filiale.
        if (! $actor->isSuperAdmin()) {
            $query->where('filiale_id', $actor->filiale_id);
        }

        return view('admin.annuaire.index', [
            'agents' => $query->get(),
        ]);
    }
}
