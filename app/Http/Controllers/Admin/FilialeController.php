<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Event;
use App\Models\EventType;
use App\Models\Filiale;
use App\Models\Scopes\FilialeScope;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Gestion des filiales de la holding (Lot D, T-ME-12). SuperAdmin UNIQUEMENT
 * (route `role:super_admin`). Pas de suppression destructive : une filiale se
 * DÉSACTIVE (réversible) — la contrainte FK `restrictOnDelete` sur events/users
 * l'interdirait de toute façon, et la désactivation empêche seulement ses comptes
 * de se connecter (voir {@see LoginController}).
 *
 * La filiale par défaut « ACS Groupe » (holding) ne peut jamais être désactivée
 * (elle porte le branding/paramétrage de repli et le compte SuperAdmin de départ).
 */
class FilialeController extends Controller
{
    public function index(): View
    {
        $filiales = Filiale::query()
            ->withCount([
                'users',
                'users as admin_count' => fn ($q) => $q->where('role', UserRole::AdminFiliale->value),
                // Les événements portent un global scope de filiale : on le retire
                // pour compter TOUS les événements de chaque filiale, indépendamment
                // du contexte sélectionné par le SuperAdmin.
                'events' => fn ($q) => $q->withoutGlobalScope(FilialeScope::class),
            ])
            ->with(['users' => fn ($q) => $q->where('role', UserRole::AdminFiliale->value)->oldest('id')->limit(1)])
            ->orderBy('name')
            ->get()
            // Filiale par défaut (holding) toujours en tête, le reste par nom.
            ->sortByDesc(fn (Filiale $f) => $f->slug === Filiale::DEFAULT_SLUG ? 1 : 0)
            ->values();

        $defaultId = Filiale::defaultId();

        return view('admin.filiales.index', [
            'filiales' => $filiales->map(fn (Filiale $f) => $this->payload($f, $defaultId))->all(),
            'kpis' => [
                'total' => $filiales->count(),
                'active' => $filiales->where('is_active', true)->count(),
                'users' => (int) $filiales->sum('users_count'),
                'events' => (int) $filiales->sum('events_count'),
                // Holding-wide, indépendant du scope de filiale (Attendance n'en porte
                // pas — rattachée à l'événement, cf. D-ME-1 sur Person).
                'attendances' => Attendance::count(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', 'unique:filiales,name'],
        ]);

        $filiale = Filiale::create([
            'name' => $data['name'],
            'slug' => $this->uniqueSlug($data['name']),
            'is_active' => true,
        ]);

        // Référentiel de types utilisable immédiatement, sans étape de paramétrage
        // requise avant de pouvoir créer un événement (D-ME-3).
        EventType::seedDefaultsFor($filiale);

        return response()->json($this->payload($filiale->loadCount('users'), Filiale::defaultId()), 201);
    }

    public function update(Request $request, Filiale $filiale): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('filiales', 'name')->ignore($filiale->id)],
        ]);

        $filiale->update(['name' => $data['name']]);

        return response()->json($this->payload($filiale, Filiale::defaultId()));
    }

    /** Active/désactive une filiale (réversible). Jamais la filiale par défaut. */
    public function toggle(Filiale $filiale): JsonResponse
    {
        if ($filiale->slug === Filiale::DEFAULT_SLUG) {
            return response()->json(['message' => 'La filiale par défaut ne peut pas être désactivée.'], 422);
        }

        $filiale->update(['is_active' => ! $filiale->is_active]);

        return response()->json($this->payload($filiale, Filiale::defaultId()));
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'filiale';

        do {
            $slug = $base.'-'.Str::lower(Str::random(4));
        } while (Filiale::where('slug', $slug)->exists());

        return $slug;
    }

    /** @return array<string, mixed> */
    private function payload(Filiale $filiale, int $defaultId): array
    {
        return [
            'id' => $filiale->id,
            'name' => $filiale->name,
            'slug' => $filiale->slug,
            'is_active' => $filiale->is_active,
            'is_default' => $filiale->id === $defaultId,
            'users_count' => (int) ($filiale->users_count ?? 0),
            'admin_count' => (int) ($filiale->admin_count ?? User::where('filiale_id', $filiale->id)->where('role', UserRole::AdminFiliale->value)->count()),
            'admin_name' => $filiale->relationLoaded('users') ? $filiale->users->first()?->name : null,
            'events_count' => (int) ($filiale->events_count ?? Event::withoutGlobalScope(FilialeScope::class)
                ->where('filiale_id', $filiale->id)->count()),
            'created_label' => $filiale->slug === Filiale::DEFAULT_SLUG
                ? 'Migration'
                : $filiale->created_at?->translatedFormat('j M Y'),
        ];
    }
}
