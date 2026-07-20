<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EventType;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * Page Paramètres (admin only) : types d'événement, comptes, branding,
 * conservation des données (lecture seule).
 */
class SettingsController extends Controller
{
    public function index(): View
    {
        return view('admin.settings.index', [
            'types' => EventType::orderBy('position')->get()
                ->map(fn (EventType $t) => [
                    'id' => $t->id, 'name' => $t->name, 'color' => $t->color,
                    'is_active' => $t->is_active, 'usage' => $t->events()->count(),
                ])->all(),
            'accounts' => User::orderBy('name')->get()
                ->map(fn (User $u) => [
                    'id' => $u->id, 'name' => $u->name, 'email' => $u->email,
                    'role' => $u->role->value, 'role_label' => $u->role->label(),
                    'is_active' => $u->is_active,
                    'last_login' => $u->last_login_at?->translatedFormat('j M Y · H:i') ?? 'Jamais connecté',
                    'is_self' => $u->is(request()->user()),
                ])->all(),
            'branding' => Setting::branding(),
        ]);
    }

    public function saveBranding(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'org_name' => ['required', 'string', 'max:120'],
            'timezone' => ['required', 'string', 'max:64'],
            'date_format' => ['required', 'string', 'max:32'],
            'logo' => ['nullable', 'image', 'mimes:png,svg,jpg,jpeg,webp', 'max:2048'],
        ]);

        Setting::set('org_name', $data['org_name']);
        Setting::set('timezone', $data['timezone']);
        Setting::set('date_format', $data['date_format']);

        if ($request->hasFile('logo')) {
            $old = Setting::get('logo_path');
            if ($old !== null) {
                Storage::disk('public')->delete($old);
            }
            Setting::set('logo_path', $request->file('logo')->store('branding', 'public'));
        }

        return redirect()->route('admin.settings.index')->with('status', 'Branding enregistré.');
    }
}
