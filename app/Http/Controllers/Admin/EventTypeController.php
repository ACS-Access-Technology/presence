<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EventType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CRUD des types d'événement (Paramètres, admin only).
 */
class EventTypeController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', 'unique:event_types,name'],
            'color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        $type = EventType::create([
            'name' => $data['name'],
            'color' => $data['color'],
            'is_active' => true,
            'position' => (int) EventType::max('position') + 1,
        ]);

        return response()->json($this->payload($type), 201);
    }

    public function update(Request $request, EventType $type): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('event_types', 'name')->ignore($type->id)],
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
