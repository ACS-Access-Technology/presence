<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Point d'entrée /admin : l'accueil du tableau de bord est la liste des
 * événements (il n'existe pas d'écran « dashboard » distinct dans les prototypes).
 * Un Agent, sans accès aux surfaces événement, atterrit sur l'annuaire.
 */
class DashboardController extends Controller
{
    public function index(Request $request): RedirectResponse
    {
        if ($request->user()->isAgent()) {
            return redirect()->route('admin.annuaire.index');
        }

        return redirect()->route('admin.events.index');
    }
}
