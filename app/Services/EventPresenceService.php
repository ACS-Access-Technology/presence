<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Attendance;
use App\Models\Event;
use Illuminate\Support\Collection;

/**
 * Prépare les données de présence d'un événement pour l'affichage (tri/filtre/live),
 * l'export CSV et les statistiques. Le statut « récurrent » est calculé sans N+1.
 */
final class EventPresenceService
{
    /** @return list<array<string, mixed>> */
    public function rows(Event $event): array
    {
        $attendances = $event->attendances()->with('person')->orderBy('checked_in_at')->get();

        $recurrentIds = Attendance::query()
            ->whereIn('person_id', $attendances->pluck('person_id'))
            ->where('event_id', '!=', $event->id)
            ->whereHas('event', fn ($q) => $q->where('starts_at', '<', $event->starts_at))
            ->distinct()
            ->pluck('person_id')
            ->all();

        return $attendances->map(fn (Attendance $a): array => [
            'id' => $a->id,
            'name' => $a->fullName(),
            'last_name' => $a->last_name,
            'first_name' => $a->first_name,
            'initials' => mb_strtoupper(mb_substr($a->first_name, 0, 1).mb_substr($a->last_name, 0, 1)),
            'email' => $a->person?->email,
            'phone' => $a->phone,
            'company' => $a->company,
            'direction' => $a->direction,
            'service' => $a->service,
            'position' => $a->position,
            'time' => $a->checked_in_at->format('H:i'),
            'time_sort' => $a->checked_in_at->getTimestamp(),
            'left' => $a->departed_at?->format('H:i'),
            'recurrent' => in_array($a->person_id, $recurrentIds, true),
            'manual' => $a->is_manual,
            'has_signature' => $a->signature_path !== null,
            'signature_url' => $a->signature_path !== null
                ? route('admin.events.attendances.signature', [$event, $a])
                : null,
            'departure_url' => route('admin.events.attendances.departure', [$event, $a]),
            'undo_url' => route('admin.events.attendances.undo-departure', [$event, $a]),
            'color' => $this->avatarColor($a->fullName()),
        ])->all();
    }

    /** @return array<string, mixed> */
    public function stats(Event $event): array
    {
        $rows = collect($this->rows($event));
        $recurrent = $rows->where('recurrent', true)->count();

        return [
            'total' => $rows->count(),
            'companies' => $rows->pluck('company')->filter()->unique()->count(),
            'recurrent' => $recurrent,
            'newcomers' => $rows->count() - $recurrent,
            'by_company' => $this->distribution($rows, 'company'),
            'by_direction' => $this->distribution($rows, 'direction'),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function distribution(Collection $rows, string $key): array
    {
        return $rows->pluck($key)
            ->filter()
            ->countBy()
            ->sortDesc()
            ->take(8)
            ->all();
    }

    /** Couleur d'avatar déterministe dérivée du nom. */
    private function avatarColor(string $name): string
    {
        $palette = ['#7c3aed', '#2563eb', '#d6336c', '#0e9e86', '#e0620d', '#1e2a78'];

        return $palette[abs(crc32($name)) % count($palette)];
    }
}
