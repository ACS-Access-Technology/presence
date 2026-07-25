<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EventType;
use App\Models\Filiale;
use App\Models\Setting;
use App\Models\User;
use App\Support\FilialeScoping;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * Page Paramètres (SuperAdmin + AdminFiliale) : types d'événement, comptes,
 * branding. Tout est cloisonné par filiale (Lots C/D/F) :
 *  - les **types** via le global scope de {@see EventType} ;
 *  - les **comptes** via {@see FilialeScoping::constrain()} (User n'a pas de global
 *    scope) ;
 *  - le **branding** est celui de la filiale du contexte (holding par défaut pour
 *    le SuperAdmin en « Toutes »), avec toggle d'héritage holding (Q-ME-8).
 */
class SettingsController extends Controller
{
    public function __construct(private readonly FilialeScoping $scoping) {}

    public function index(Request $request): View
    {
        $actor = $request->user();
        $isSuper = $actor->isSuperAdmin();

        // Comptes scopés au contexte. Le SuperAdmin voit la colonne Filiale et la
        // réassignation ; l'AdminFiliale ne voit que sa filiale.
        $accounts = $this->scoping->constrain(User::query())
            ->with('filiale')
            ->orderBy('name')
            ->get()
            ->map(fn (User $u) => [
                'id' => $u->id, 'name' => $u->name, 'email' => $u->email,
                'role' => $u->role->value, 'role_label' => $u->role->label(),
                'is_active' => $u->is_active,
                'filiale_id' => $u->filiale_id,
                'filiale_name' => $u->filiale?->name,
                'last_login' => $u->last_login_at?->translatedFormat('j M Y · H:i') ?? 'Jamais connecté',
                'is_self' => $u->is($actor),
                'can_manage' => $actor->can('manage', $u),
            ])->all();

        // Fail-closed (RME-7) : un compte non-SuperAdmin sans filiale (incohérence de
        // données) ne doit voir NI comptes NI branding — pas de repli holding silencieux.
        abort_if($this->scoping->shouldDenyAll(), 403);

        // Filiale dont on édite le branding : celle du contexte, sinon la holding.
        $brandingFilialeId = $this->scoping->filialeId() ?? Filiale::defaultId();
        $isHolding = $brandingFilialeId === Filiale::defaultId();

        return view('admin.settings.index', [
            'isSuper' => $isSuper,
            'types' => EventType::orderBy('position')->get()
                ->map(fn (EventType $t) => [
                    'id' => $t->id, 'name' => $t->name, 'color' => $t->color,
                    'is_active' => $t->is_active, 'usage' => $t->events()->count(),
                ])->all(),
            'accounts' => $accounts,
            // Contexte brut du sélecteur topbar (null = « Toutes les filiales »).
            // Sert à pré-remplir/verrouiller la filiale à l'invitation d'un compte :
            // pas de choix redondant si le SuperAdmin travaille déjà dans une filiale.
            'contextFilialeId' => $isSuper ? $this->scoping->filialeId() : null,
            'filiales' => $isSuper
                ? Filiale::orderBy('name')->get(['id', 'name'])->map(fn (Filiale $f) => ['id' => $f->id, 'name' => $f->name])->all()
                : [],
            'branding' => Setting::branding($brandingFilialeId),
            'brandingIsHolding' => $isHolding,
            'brandInherits' => ! $isHolding && Setting::inheritsBranding($brandingFilialeId),
            'brandingFilialeName' => $isHolding ? 'ACS Groupe (holding)' : Filiale::find($brandingFilialeId)?->name,
        ]);
    }

    /**
     * Enregistre le branding de la filiale du contexte. La holding porte en plus
     * les réglages GLOBAUX (fuseau, format de date) ; une filiale ne les surcharge
     * jamais (Q-ME-8) et peut hériter du branding holding.
     */
    public function saveBranding(Request $request): RedirectResponse
    {
        abort_if($this->scoping->shouldDenyAll(), 403);

        $filialeId = $this->scoping->filialeId() ?? Filiale::defaultId();
        $isHolding = $filialeId === Filiale::defaultId();

        $rules = [
            'org_name' => ['required', 'string', 'max:120'],
            'accent_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'logo' => ['nullable', 'image', 'mimes:png,svg,jpg,jpeg,webp', 'max:2048'],
        ];

        if ($isHolding) {
            $rules['timezone'] = ['required', 'string', 'max:64'];
            $rules['date_format'] = ['required', 'string', 'max:32'];
        } else {
            $rules['brand_inherit'] = ['sometimes', 'boolean'];
        }

        $data = $request->validate($rules);

        // Filiale héritant du branding holding : on n'enregistre que le choix.
        if (! $isHolding) {
            $inherit = $request->boolean('brand_inherit');
            Setting::set('brand_inherit', $inherit ? '1' : '0', $filialeId);

            if ($inherit) {
                return redirect()->route('admin.settings.index')
                    ->with('status', 'Branding : héritage du branding de la holding activé.');
            }
        }

        Setting::set('org_name', $data['org_name'], $filialeId);
        Setting::set('accent_color', $data['accent_color'] ?? null, $filialeId);

        if ($isHolding) {
            // Réglages globaux : uniquement au niveau holding.
            Setting::set('timezone', $data['timezone'], $filialeId);
            Setting::set('date_format', $data['date_format'], $filialeId);
        }

        if ($request->hasFile('logo')) {
            $old = Setting::get('logo_path', $filialeId);
            if ($old !== null) {
                Storage::disk('public')->delete($old);
            }
            Setting::set('logo_path', $request->file('logo')->store('branding', 'public'), $filialeId);
        }

        return redirect()->route('admin.settings.index')->with('status', 'Branding enregistré.');
    }
}
