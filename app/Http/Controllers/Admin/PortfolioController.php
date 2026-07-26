<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventType;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Portfolio : galerie des activités documentées (compte-rendu, documents ou photos).
 */
class PortfolioController extends Controller
{
    public function index(): View
    {
        $events = Event::query()
            ->with(['type', 'report', 'photos' => fn ($q) => $q->orderBy('position')])
            ->withCount(['documents', 'photos'])
            ->where(function ($q): void {
                $q->whereHas('report', fn ($r) => $r->whereNotNull('body')->where('body', '!=', ''))
                    ->orHas('documents')
                    ->orHas('photos');
            })
            ->orderByDesc('starts_at')
            ->get();

        $cards = $events->map(function (Event $event): array {
            $cover = $event->photos->first();

            return [
                'title' => $event->title,
                'type' => $event->type->name,
                'type_color' => $event->type->color,
                'date' => $event->starts_at->translatedFormat('j M Y'),
                'location' => $event->location,
                'url' => route('admin.portfolio.show', $event),
                'cover' => $cover?->url(),
                'excerpt' => $this->excerpt($event),
                'photos_count' => $event->photos_count,
                'documents_count' => $event->documents_count,
                'search' => Str::lower($event->title.' '.$event->location.' '.$event->type->name),
            ];
        })->all();

        return view('admin.portfolio.index', [
            'cards' => $cards,
            'kpis' => [
                'activities' => $events->count(),
                'photos' => (int) $events->sum('photos_count'),
                'documents' => (int) $events->sum('documents_count'),
            ],
            'types' => EventType::orderBy('position')->get(),
        ]);
    }

    /** Galerie photo d'une activité : clic sur une carte du portfolio. */
    public function show(Event $event): View
    {
        $event->load(['type', 'photos' => fn ($q) => $q->orderBy('position')]);

        return view('admin.portfolio.show', [
            'event' => $event,
            'photos' => $event->photos->map(fn ($p) => ['id' => $p->id, 'url' => $p->url()])->values()->all(),
        ]);
    }

    /** Extrait lisible du compte-rendu (markdown allégé). */
    private function excerpt(Event $event): ?string
    {
        $body = $event->report?->body;
        if ($body === null || trim($body) === '') {
            return null;
        }

        return Str::of($body)
            ->replaceMatches('/[#*_>`\[\]()-]/', ' ')
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->limit(140)
            ->value();
    }
}
