<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Event;
use App\Models\Person;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Dashboard statistiques global : toutes activités confondues (par opposition
 * aux statistiques par événement déjà présentes sur la fiche événement).
 */
class StatisticsController extends Controller
{
    public function index(): View
    {
        $events = Event::query()->with('type')->withCount('attendances')->get();
        $attendances = Attendance::query()->with('event.type')->get();

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

        $topRecurrent = Person::query()
            ->has('attendances')
            ->withCount('attendances')
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
        ]);
    }
}
