<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Person;
use Illuminate\View\View;

/**
 * Annuaire des participants : recherche par nom, historique multi-événements
 * et statistiques par personne.
 */
class ParticipantController extends Controller
{
    public function index(): View
    {
        $people = Person::query()
            ->has('attendances')
            ->withCount('attendances')
            ->withMax('attendances', 'checked_in_at')
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
                    ? \Illuminate\Support\Carbon::parse($p->attendances_max_checked_in_at)->translatedFormat('j M Y')
                    : '—',
                'is_staff' => $p->is_staff,
                'url' => route('admin.participants.show', $p),
                'search' => \Illuminate\Support\Str::lower($p->fullName().' '.$p->company.' '.$p->direction),
            ])->all();

        return view('admin.participants.index', ['people' => $people]);
    }

    public function show(Person $person): View
    {
        $attendances = $person->attendances()
            ->with('event.type')
            ->orderByDesc('checked_in_at')
            ->get();

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
