<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;

/**
 * Point d'entrée /admin : l'accueil du tableau de bord est la liste des
 * événements (il n'existe pas d'écran « dashboard » distinct dans les prototypes).
 */
class DashboardController extends Controller
{
    public function index(): RedirectResponse
    {
        return redirect()->route('admin.events.index');
    }
}
