<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EventType;
use App\Models\Filiale;
use App\Models\Scopes\FilialeScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * CRUD des types d'événement (Paramètres). Ouvert à SuperAdmin et AdminFiliale,
 * cloisonné par filiale (Lot F, T-ME-20) :
 *
 *  - Le global scope {@see FilialeScope} sur {@see EventType}
 *    rend introuvable (404) le type d'une autre filiale au route-model binding
 *    `{type}` — ferme l'IDOR de la revue de sécurité, sans `where` explicite.
 *  - L'unicité du nom est désormais `(filiale_id, name)` : deux filiales peuvent
 *    avoir un type homonyme.
 *  - Un type créé est rattaché à la filiale du contexte courant.
 */
class EventTypeController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        // Filiale cible EXPLICITE (anomalie P1) : depuis « Toutes les filiales », un
        // SuperAdmin doit choisir — plus de repli silencieux sur « ACS Groupe ».
        $requested = $request->input('filiale_id');
        $filialeId = Filiale::resolveForWebCreation(
            $requested !== null && $requested !== '' ? (int) $requested : null,
        );

        if ($filialeId === null) {
            throw ValidationException::withMessages([
                'filiale_id' => 'Sélectionnez la filiale pour laquelle créer ce type.',
            ]);
        }

        if (! Filiale::whereKey($filialeId)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages([
                'filiale_id' => 'La filiale sélectionnée est introuvable ou désactivée.',
            ]);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:60',
                Rule::unique('event_types', 'name')->where('filiale_id', $filialeId)],
            'color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        $type = EventType::create([
            'filiale_id' => $filialeId,
            'name' => $data['name'],
            'color' => $data['color'],
            'is_active' => true,
            'position' => (int) EventType::where('filiale_id', $filialeId)->max('position') + 1,
        ]);

        return response()->json($this->payload($type), 201);
    }

    public function update(Request $request, EventType $type): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60',
                Rule::unique('event_types', 'name')
                    ->where('filiale_id', $type->filiale_id)
                    ->ignore($type->id)],
            'color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $type->update($data);

        return response()->json($this->payload($type));
    }

    public function destroy(EventType $type): JsonResponse
    {
        if (! $type->isDeletable()) {
            return response()->json([
                'message' => 'Ce type est utilisé par des événements et ne peut pas être supprimé. Désactivez-le plutôt.',
            ], 422);
        }

        $type->delete();

        return response()->json(['deleted' => true]);
    }

    /** @return array<string, mixed> */
    private function payload(EventType $type): array
    {
        return [
            'id' => $type->id,
            'name' => $type->name,
            'color' => $type->color,
            'is_active' => $type->is_active,
            'usage' => $type->events()->count(),
        ];
    }
}
