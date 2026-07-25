<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Event;
use App\Models\Filiale;
use App\Models\Person;
use App\Support\FilialeScoping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Dashboard statistiques global : toutes activités confondues (par opposition
 * aux statistiques par événement déjà présentes sur la fiche événement).
 *
 * Cloisonné par filiale (Lot C, T-ME-10) : {@see Event} est globalement scopé ;
 * les requêtes sur {@see Attendance} et {@see Person} le suivent via
 * `whereHas('event')`. En contexte « Toutes les filiales » (SuperAdmin), on
 * ajoute une ventilation PAR filiale (Q-ME-6) plutôt que de simples totaux.
 */
class StatisticsController extends Controller
{
    public function index(FilialeScoping $scoping): View
    {
        $events = Event::query()->with('type')->withCount('attendances')->get();
        // `whereHas('event')` scope les présences aux événements visibles.
        $attendances = Attendance::query()->whereHas('event')->with('event.type')->get();

        $kpis = [
            'total_events' => $events->count(),
            'total_attendances' => $attendances->count(),
            'total_people' => $attendances->pluck('person_id')->unique()->count(),
            'avg_per_event' => $events->count() > 0
                ? round($attendances->count() / $events->count(), 1)
                : 0.0,
        ];

        $byType = $events->groupBy(fn (Event $e) => $e->type->name)
            ->map(fn (Collection $g) => $g->sum('attendances_count'))
            ->sortDesc();

        $months = collect(range(11, 0))->map(fn (int $i) => Carbon::now()->subMonths($i)->startOfMonth());
        $byMonth = $months->mapWithKeys(function (Carbon $month) use ($attendances): array {
            $count = $attendances->filter(fn (Attendance $a) => $a->checked_in_at->isSameMonth($month))->count();

            return [$month->translatedFormat('M Y') => $count];
        });

        $topCompanies = $attendances->pluck('company')->filter()->countBy()->sortDesc()->take(10);

        $inScope = fn ($q) => $q->whereHas('event');
        $topRecurrent = Person::query()
            ->whereHas('attendances', $inScope)
            ->withCount(['attendances' => $inScope])
            ->orderByDesc('attendances_count')
            ->limit(10)
            ->get()
            ->filter(fn (Person $p) => $p->attendances_count > 1)
            ->values();

        return view('admin.statistics.index', [
            'kpis' => $kpis,
            'byType' => $byType,
            'byMonth' => $byMonth,
            'topCompanies' => $topCompanies,
            'topRecurrent' => $topRecurrent,
            // Ventilation par filiale, uniquement en vue « Toutes » (SuperAdmin) :
            // le contexte est actif mais sans filiale ciblée. Sinon `null` (la vue
            // n'affiche pas la section).
            'byFiliale' => $this->breakdownByFiliale($scoping, $events),
        ]);
    }

    /**
     * Émargements et événements ventilés par filiale, pour la vue transversale
     * « Toutes les filiales » du SuperAdmin (Q-ME-6). `null` hors de ce contexte.
     *
     * @param  Collection<int, Event>  $events
     * @return Collection<int, array{name: string, events: int, attendances: int}>|null
     */
    private function breakdownByFiliale(FilialeScoping $scoping, Collection $events): ?Collection
    {
        // Actif + aucune filiale ciblée = « Toutes » (SuperAdmin). Un contexte
        // inactif (hors admin) ou ciblé (AdminFiliale / filiale sélectionnée) → pas
        // de ventilation.
        if (! $scoping->isActive() || $scoping->filialeId() !== null) {
            return null;
        }

        $names = Filiale::query()->pluck('name', 'id');

        return $events
            ->groupBy('filiale_id')
            ->map(fn (Collection $group, int $filialeId): array => [
                'name' => $names[$filialeId] ?? 'Filiale supprimée',
                'events' => $group->count(),
                'attendances' => (int) $group->sum('attendances_count'),
            ])
            ->sortByDesc('attendances')
            ->values();
    }
}
