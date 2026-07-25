<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Person;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Annuaire des participants : recherche par nom, historique multi-événements
 * et statistiques par personne.
 */
class ParticipantController extends Controller
{
    public function index(): View
    {
        // Historique STRICTEMENT scopé à la filiale (Q-ME-4) : bien que `Person`
        // reste unifié au niveau holding (D-ME-1), on ne compte et ne liste que les
        // personnes ayant émargé sur un événement VISIBLE dans le contexte courant.
        // `whereHas('event')` s'appuie sur le global scope de {@see Event} : en
        // contexte admin il filtre par filiale ; en « Toutes » (SuperAdmin) il
        // laisse tout passer ; en fail-closed il ne renvoie rien.
        $inScope = fn ($q) => $q->whereHas('event');

        $people = Person::query()
            ->whereHas('attendances', $inScope)
            ->withCount(['attendances' => $inScope])
            ->withMax(['attendances' => $inScope], 'checked_in_at')
            ->orderByRaw('attendances_max_checked_in_at DESC')
            ->get()
            ->map(fn (Person $p): array => [
                'id' => $p->id,
                'name' => $p->fullName(),
                'initials' => $this->initials($p),
                'color' => $this->avatarColor($p->fullName()),
                'detail' => collect([$p->company, $p->direction])->filter()->implode(' · '),
                'attendances' => $p->attendances_count,
                'last' => $p->attendances_max_checked_in_at
                    ? Carbon::parse($p->attendances_max_checked_in_at)->translatedFormat('j M Y')
                    : '—',
                'is_staff' => $p->is_staff,
                'url' => route('admin.participants.show', $p),
                'search' => Str::lower($p->fullName().' '.$p->company.' '.$p->direction),
            ])->all();

        return view('admin.participants.index', ['people' => $people]);
    }

    public function show(Person $person): View
    {
        // Historique limité aux événements visibles dans le contexte (Q-ME-4).
        // `Person` n'a pas de global scope (référentiel holding), donc l'URL
        // directe résout n'importe quelle personne : on renvoie 404 si elle n'a
        // aucun émargement dans le périmètre courant (pas de fuite cross-filiale).
        $attendances = $person->attendances()
            ->whereHas('event')
            ->with('event.type')
            ->orderByDesc('checked_in_at')
            ->get();

        abort_if($attendances->isEmpty(), 404);

        $history = $attendances->map(fn (Attendance $a): array => [
            'event_title' => $a->event->title,
            'event_url' => route('admin.events.show', $a->event),
            'type' => $a->event->type->name,
            'type_color' => $a->event->type->color,
            'date' => $a->event->starts_at->translatedFormat('j M Y'),
            'time' => $a->checked_in_at->format('H:i'),
            'left' => $a->departed_at?->format('H:i'),
            'manual' => $a->is_manual,
        ])->all();

        return view('admin.participants.show', [
            'person' => $person,
            'initials' => $this->initials($person),
            'color' => $this->avatarColor($person->fullName()),
            'history' => $history,
            'stats' => [
                'events' => $attendances->count(),
                'first' => $attendances->min('checked_in_at')?->translatedFormat('j M Y') ?? '—',
                'last' => $attendances->max('checked_in_at')?->translatedFormat('j M Y') ?? '—',
                'companies' => $attendances->pluck('company')->filter()->unique()->count(),
            ],
        ]);
    }

    private function initials(Person $p): string
    {
        return mb_strtoupper(mb_substr($p->first_name, 0, 1).mb_substr($p->last_name, 0, 1));
    }

    private function avatarColor(string $name): string
    {
        $palette = ['#7c3aed', '#2563eb', '#d6336c', '#0e9e86', '#e0620d', '#1e2a78'];

        return $palette[abs(crc32($name)) % count($palette)];
    }
}
