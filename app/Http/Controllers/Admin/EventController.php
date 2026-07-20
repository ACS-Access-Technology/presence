<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreEventRequest;
use App\Http\Requests\Admin\UpdateEventRequest;
use App\Models\Event;
use App\Models\EventSeries;
use App\Models\EventType;
use App\Services\EventPresenceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Liste, création et détail des événements (accès partagé : tout compte
 * interne voit tout). Pas de mode brouillon : créer = publier directement.
 */
class EventController extends Controller
{
    public function __construct(private readonly EventPresenceService $presence) {}

    public function index(): View
    {
        $events = Event::query()
            ->with(['type', 'reschedules' => fn ($q) => $q->latest()->limit(1)])
            ->withCount('attendances')
            ->orderByDesc('starts_at')
            ->get();

        $kpis = [
            'live' => $events->filter(fn (Event $e) => $e->status()->value === 'en_cours')->count(),
            'soon' => $events->filter(fn (Event $e) => $e->status()->value === 'a_venir')->count(),
            'done' => $events->filter(fn (Event $e) => $e->status()->value === 'clos')->count(),
            'total_attendances' => (int) $events->sum('attendances_count'),
        ];

        return view('admin.events.index', compact('events', 'kpis'));
    }

    public function show(Event $event): View
    {
        $event->load('type', 'report', 'documents', 'photos');
        $lastReschedule = $event->reschedules()->latest()->first();
        $siblingSeances = $event->event_series_id !== null
            ? Event::where('event_series_id', $event->event_series_id)
                ->where('id', '!=', $event->id)
                ->withCount('attendances')
                ->orderBy('series_position')
                ->get()
            : collect();

        return view('admin.events.show', [
            'event' => $event,
            'types' => EventType::where('is_active', true)->orWhere('id', $event->event_type_id)->orderBy('position')->get(),
            'lastReschedule' => $lastReschedule,
            'siblingSeances' => $siblingSeances,
            'rows' => $this->presence->rows($event),
            'stats' => $this->presence->stats($event),
            'report' => $event->report,
            'documents' => $event->documents
                ->map(fn ($d) => [
                    'id' => $d->id, 'name' => $d->original_name, 'url' => $d->url(), 'size' => $d->size,
                    'delete_url' => route('admin.events.report.documents.destroy', [$event, $d]),
                ])->all(),
            'photos' => $event->photos->sortBy('position')
                ->map(fn ($p) => [
                    'id' => $p->id, 'url' => $p->url(),
                    'delete_url' => route('admin.events.report.photos.destroy', [$event, $p]),
                ])->values()->all(),
        ]);
    }

    public function create(): View
    {
        return view('admin.events.create', [
            'types' => EventType::where('is_active', true)->orderBy('position')->get(),
        ]);
    }

    public function store(StoreEventRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $extraSeances = $data['extra_seances'] ?? [];

        $firstEvent = DB::transaction(function () use ($data, $extraSeances, $request): Event {
            $series = null;
            if ($extraSeances !== []) {
                $series = EventSeries::create([
                    'title' => $data['title'],
                    'event_type_id' => $data['event_type_id'],
                    'location' => $data['location'] ?? null,
                    'created_by' => $request->user()?->id,
                ]);
            }

            $seances = [['date' => $data['date'], 'start' => $data['start'], 'end' => $data['end']], ...$extraSeances];
            $events = [];

            foreach ($seances as $position => $seance) {
                $events[] = Event::create([
                    'title' => $data['title'],
                    'event_type_id' => $data['event_type_id'],
                    'starts_at' => Carbon::parse($seance['date'].' '.$seance['start']),
                    'ends_at' => Carbon::parse($seance['date'].' '.$seance['end']),
                    'location' => $data['location'] ?? null,
                    'qr_mode' => $data['qr_mode'],
                    'qr_secret' => Str::random(32),
                    'public_slug' => $this->uniqueSlug($data['title']),
                    'created_by' => $request->user()?->id,
                    'event_series_id' => $series?->id,
                    'series_position' => $series !== null ? $position + 1 : null,
                ]);
            }

            foreach ($data['invitees'] ?? [] as $personId) {
                foreach ($events as $event) {
                    $event->invitations()->create([
                        'person_id' => $personId,
                        'invited_by' => $request->user()?->id,
                    ]);
                }
            }

            return $events[0];
        });

        $message = $extraSeances !== []
            ? 'Série créée : '.(count($extraSeances) + 1).' séances.'
            : 'Événement créé.';

        return redirect()->route('admin.events.show', $firstEvent)->with('status', $message);
    }

    /** Corrige titre / type / lieu (ex. faute de frappe) — n'affecte ni les horaires ni le mode QR. */
    public function update(UpdateEventRequest $request, Event $event): RedirectResponse
    {
        $event->update($request->validated());

        return back()->with('status', 'Événement modifié.');
    }

    /** Slug public unique, dérivé du titre (retry avec suffixe aléatoire en cas de collision). */
    private function uniqueSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'evenement';
        $slug = $base;

        while (Event::where('public_slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(4));
        }

        return $slug;
    }
}
