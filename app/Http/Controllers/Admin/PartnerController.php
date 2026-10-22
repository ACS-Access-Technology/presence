<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SavePartnerRequest;
use App\Models\Event;
use App\Models\Filiale;
use App\Models\Partner;
use App\Support\FilialeScoping;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * Rencontres partenaires : référentiel des partenaires (nom, logo, couleurs,
 * contact) et liste de leurs rencontres. La création d'une rencontre réutilise
 * le formulaire d'événement, partenaire présélectionné.
 */
final class PartnerController extends Controller
{
    public function index(Request $request, FilialeScoping $scoping): View
    {
        $mustChooseFiliale = $request->user()->isSuperAdmin() && $scoping->filialeId() === null;

        return view('admin.partners.index', [
            'partners' => Partner::query()->withCount('events')->orderBy('name')->get(),
            'meetings' => Event::query()->whereNotNull('partner_id')->with('partner')
                ->orderByDesc('starts_at')->limit(30)->get(),
            'mustChooseFiliale' => $mustChooseFiliale,
            'filiales' => $mustChooseFiliale
                ? Filiale::where('is_active', true)->orderBy('name')->get(['id', 'name'])
                : collect(),
        ]);
    }

    public function store(SavePartnerRequest $request): RedirectResponse
    {
        $data = $request->validated();

        Partner::create([
            'filiale_id' => $request->targetFilialeId(),
            'name' => $data['name'],
            'logo_path' => $request->file('logo')->store('partners', 'public'),
            'background_path' => $request->hasFile('background') ? $request->file('background')->store('partners', 'public') : null,
            'primary_color' => strtolower($data['primary_color']),
            'secondary_color' => isset($data['secondary_color']) ? strtolower($data['secondary_color']) : null,
            'is_institutional' => $request->boolean('is_institutional'),
            'require_geolocation' => $request->boolean('require_geolocation', true),
            'contact_name' => $data['contact_name'] ?? null,
            'contact_email' => $data['contact_email'] ?? null,
        ]);

        return redirect()->route('admin.partners.index')->with('status', 'Partenaire enregistré.');
    }

    public function update(SavePartnerRequest $request, Partner $partner): RedirectResponse
    {
        $data = $request->validated();
        $attributes = [
            'name' => $data['name'],
            'is_institutional' => $request->boolean('is_institutional'),
            'require_geolocation' => $request->boolean('require_geolocation', true),
            'primary_color' => strtolower($data['primary_color']),
            'secondary_color' => isset($data['secondary_color']) ? strtolower($data['secondary_color']) : null,
            'contact_name' => $data['contact_name'] ?? null,
            'contact_email' => $data['contact_email'] ?? null,
        ];

        $obsolete = [];
        if ($request->hasFile('logo')) {
            $obsolete[] = $partner->logo_path;
            $attributes['logo_path'] = $request->file('logo')->store('partners', 'public');
        }
        if ($request->hasFile('background')) {
            $obsolete[] = $partner->background_path;
            $attributes['background_path'] = $request->file('background')->store('partners', 'public');
        } elseif ($request->boolean('remove_background')) {
            $obsolete[] = $partner->background_path;
            $attributes['background_path'] = null;
        }

        $partner->update($attributes);

        // Anciens fichiers supprimés seulement APRÈS la mise à jour réussie : un
        // échec en base ne doit jamais laisser le partenaire pointer vers un
        // fichier effacé.
        foreach (array_filter($obsolete) as $path) {
            Storage::disk('public')->delete($path);
        }

        return redirect()->route('admin.partners.index')->with('status', 'Partenaire mis à jour.');
    }
}
