<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Person;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Recherche du référentiel « Personnel ACS Groupe » (is_staff) pour la
 * combobox d'invitation à la création d'un événement.
 */
class PersonSearchController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        $people = Person::query()
            ->where('is_staff', true)
            ->when($q !== '', function ($query) use ($q): void {
                $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%';
                $query->where(function ($w) use ($like): void {
                    $w->where('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like)
                        ->orWhere('email', 'like', $like)
                        ->orWhere('direction', 'like', $like)
                        ->orWhere('service', 'like', $like);
                });
            })
            ->orderBy('last_name')
            ->limit(8)
            ->get()
            ->map(fn (Person $p): array => [
                'id' => $p->id,
                'name' => $p->fullName(),
                'initials' => mb_strtoupper(mb_substr($p->first_name, 0, 1).mb_substr($p->last_name, 0, 1)),
                'detail' => collect([$p->direction, $p->position])->filter()->implode(' · '),
            ]);

        return response()->json(['people' => $people]);
    }
}
