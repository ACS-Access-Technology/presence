<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreEventRequest;
use App\Http\Requests\Admin\UpdateEventRequest;
use App\Mail\EventInvitationMail;
use App\Models\Event;
use App\Models\EventSeries;
use App\Models\EventType;
use App\Models\Filiale;
use App\Models\Scopes\FilialeScope;
use App\Services\EventPresenceService;
use App\Support\FilialeScoping;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
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
        $event->load('type', 'documents', 'photos');
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
            // Transfert vers une filiale (SuperAdmin uniquement, T-ME-14) : liste
            // des filiales cibles possibles (hors celle de l'événement) avec leurs
            // types actifs, pour peupler le sélecteur dépendant côté client. `null`
            // pour les autres rôles → aucune action de transfert exposée.
            'transferFiliales' => $this->transferFiliales($event),
            'rows' => $this->presence->rows($event),
            'stats' => $this->presence->stats($event),
            'pendingInvitees' => $this->presence->pendingInvitees($event),
            'feedbackStats' => $this->presence->feedbackStats($event),
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

    /**
     * Formulaire de création. La filiale de rattachement est rendue EXPLICITE
     * (anomalie P1) :
     *  - contexte filiale précis (AdminFiliale/Organisateur, ou SuperAdmin ayant
     *    sélectionné une filiale en topbar) → affichée en lecture seule ;
     *  - SuperAdmin en « Toutes les filiales » → un choix devient obligatoire, et
     *    les types proposés sont filtrés (côté client) sur la filiale choisie.
     */
    public function create(Request $request, FilialeScoping $scoping): View
    {
        $contextFilialeId = $scoping->filialeId();
        $mustChooseFiliale = $request->user()->isSuperAdmin() && $contextFilialeId === null;

        return view('admin.events.create', [
            // En mode « choix », le global scope est no-op : on inclut le filiale_id
            // de chaque type pour permettre le filtrage client par filiale.
            'types' => EventType::where('is_active', true)->orderBy('position')->get(),
            'mustChooseFiliale' => $mustChooseFiliale,
            'contextFilialeName' => $contextFilialeId !== null ? Filiale::find($contextFilialeId)?->name : null,
            'filiales' => $mustChooseFiliale
                ? Filiale::where('is_active', true)->orderBy('name')->get(['id', 'name'])
                : collect(),
        ]);
    }

    public function store(StoreEventRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $extraSeances = $data['extra_seances'] ?? [];

        // Filiale cible résolue et validée (jamais un repli silencieux). Appliquée
        // à TOUTES les séances de la série (une série ne s'étale pas sur plusieurs
        // filiales).
        $filialeId = $request->resolvedFilialeId();

        $firstEvent = DB::transaction(function () use ($data, $extraSeances, $request, $filialeId): Event {
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
                    'filiale_id' => $filialeId,
                    'title' => $data['title'],
                    'event_type_id' => $data['event_type_id'],
                    'starts_at' => Carbon::parse($seance['date'].' '.$seance['start']),
                    'ends_at' => Carbon::parse($seance['date'].' '.$seance['end']),
                    'grace_check_in_enabled' => $data['grace_check_in_enabled'] ?? false,
                    'location' => $data['location'] ?? null,
                    'geofence_latitude' => $data['geofence_latitude'] ?? null,
                    'geofence_longitude' => $data['geofence_longitude'] ?? null,
                    'geofence_radius_m' => $data['geofence_radius_m'] ?? null,
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
                    $invitation = $event->invitations()->create([
                        'person_id' => $personId,
                        'invited_by' => $request->user()?->id,
                    ]);

                    // Une invitation par séance (pas de fusion multi-jours) : chaque
                    // email porte la bonne date/heure de SA séance dans son .ics.
                    Mail::to($invitation->person->email)->queue(new EventInvitationMail($invitation));
                    $invitation->update(['notified_at' => Carbon::now()]);
                }
            }

            return $events[0];
        });

        $message = $extraSeances !== []
            ? 'Série créée : '.(count($extraSeances) + 1).' séances.'
            : 'Événement créé.';

        return redirect()->route('admin.events.show', $firstEvent)->with('status', $message);
    }

    /**
     * Filiales cibles d'un transfert d'événement (SuperAdmin uniquement).
     * Chaque filiale porte ses types d'événement actifs — les types étant
     * cloisonnés par filiale (Q-ME-10), le SuperAdmin doit choisir un type
     * existant dans la filiale de destination. Retourne `null` pour tout autre
     * rôle (aucune action de transfert exposée dans la vue).
     *
     * @return list<array{id:int, name:string, types:list<array<string,mixed>>}>|null
     */
    private function transferFiliales(Event $event): ?array
    {
        if (! auth()->user()?->isSuperAdmin()) {
            return null;
        }

        return Filiale::query()
            ->where('id', '!=', $event->filiale_id)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Filiale $filiale): array => [
                'id' => $filiale->id,
                'name' => $filiale->name,
                // Types de la filiale CIBLE (autre que le contexte courant) : le
                // global scope doit être levé pour les voir.
                'types' => EventType::withoutGlobalScope(FilialeScope::class)
                    ->where('filiale_id', $filiale->id)
                    ->where('is_active', true)
                    ->orderBy('position')
                    ->get(['id', 'name', 'color'])
                    ->all(),
            ])
            ->all();
    }

    /** Corrige titre / type / lieu / périmètre anti-fraude — n'affecte ni les horaires ni le mode QR. */
    public function update(UpdateEventRequest $request, Event $event): RedirectResponse
    {
        $event->update($request->validated());

        return back()->with('status', 'Événement modifié.');
    }

    /**
     * Slug public dérivé du titre + suffixe aléatoire systématique : la géoloc
     * n'étant pas un vrai geofence, un slug prévisible (juste le titre) permettrait
     * à un tiers de deviner l'URL d'un événement statique et d'émarger à distance
     * sans jamais avoir scanné le QR. Le suffixe élève ce coût.
     */
    private function uniqueSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'evenement';

        do {
            $slug = $base.'-'.Str::lower(Str::random(6));
        } while (Event::where('public_slug', $slug)->exists());

        return $slug;
    }
}
