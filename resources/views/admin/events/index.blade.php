@extends('layouts.admin', ['nav' => 'events'])

@section('title', 'Événements')

@php
    use App\Enums\EventStatus;
    $statusTag = [
        'en_cours' => ['tag--live', 'En cours'],
        'a_venir' => ['tag--soon', 'À venir'],
        'clos' => ['tag--done', 'Clos'],
        'annule' => ['tag--cancelled', 'Annulé'],
    ];
@endphp

@section('content')
    <div class="pagehead">
        <h1>Événements</h1>
        <p>Créez, suivez et exportez la présence de vos activités.</p>
        <a class="btn btn--primary" href="{{ route('admin.events.create') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M12 5v14M5 12h14"/></svg>
            Nouvel événement
        </a>
    </div>

    <div class="kpis">
        <div class="kpi"><span class="ic" style="background:var(--success-soft);color:var(--success)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M12 8v4l3 2"/></svg></span><div><div class="kpi__val">{{ $kpis['live'] }}</div><div class="kpi__lbl">En cours</div></div></div>
        <div class="kpi"><span class="ic" style="background:var(--accent-soft);color:var(--accent)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg></span><div><div class="kpi__val">{{ $kpis['soon'] }}</div><div class="kpi__lbl">À venir</div></div></div>
        <div class="kpi"><span class="ic" style="background:var(--surface-3);color:var(--muted)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg></span><div><div class="kpi__val">{{ $kpis['done'] }}</div><div class="kpi__lbl">Clos</div></div></div>
        <div class="kpi"><span class="ic" style="background:var(--brand-orange-soft);color:var(--brand-orange)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg></span><div><div class="kpi__val">{{ number_format($kpis['total_attendances'], 0, ',', ' ') }}</div><div class="kpi__lbl">Présences cumulées</div></div></div>
    </div>

    <div class="toolbar">
        <label class="searchbar">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
            <input id="evsearch" type="search" placeholder="Rechercher un événement (titre, lieu…)" aria-label="Rechercher un événement" oninput="EvList.filter()">
        </label>
        <div class="segbar" role="group" aria-label="Filtrer par statut">
            <button aria-pressed="true" data-s="all" onclick="EvList.setStatus('all',this)">Tous</button>
            <button aria-pressed="false" data-s="en_cours" onclick="EvList.setStatus('en_cours',this)">En cours</button>
            <button aria-pressed="false" data-s="a_venir" onclick="EvList.setStatus('a_venir',this)">À venir</button>
            <button aria-pressed="false" data-s="clos" onclick="EvList.setStatus('clos',this)">Clos</button>
            <button aria-pressed="false" data-s="annule" onclick="EvList.setStatus('annule',this)">Annulés</button>
        </div>
        <div class="viewtgl" role="group" aria-label="Affichage">
            <button id="ev-v-grid" aria-pressed="true" onclick="EvList.setView('grid')" aria-label="Vue cartes"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg></button>
            <button id="ev-v-list" aria-pressed="false" onclick="EvList.setView('list')" aria-label="Vue tableau"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg></button>
        </div>
    </div>

    @php
        $months = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
    @endphp

    <div id="ev-grid" class="grid">
        @forelse ($events as $event)
            @php
                $st = $event->status()->value;
                $reported = $event->reschedules->isNotEmpty();
                $lastResched = $event->reschedules->first();
                $tint = 'background:color-mix(in srgb, '.$event->type->color.' 14%, transparent);color:'.$event->type->color;
            @endphp
            <a class="ev {{ $st === 'annule' ? 'is-cancelled' : '' }}" href="{{ route('admin.events.show', $event) }}" data-status="{{ $st }}" data-search="{{ Str::lower($event->title.' '.$event->location.' '.$event->type->name) }}">
                <div class="ev__top">
                    <div class="ev__date" style="{{ $tint }}"><span class="d">{{ $event->starts_at->day }}</span><span class="m">{{ mb_strtoupper($months[$event->starts_at->month - 1]) }}</span></div>
                    <div class="ev__hd">
                        <div class="ev__t {{ $st === 'annule' ? 'is-cancelled' : '' }}">{{ $event->title }}</div>
                        <div class="ev__m"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M12 8v4l3 2"/></svg>{{ $event->starts_at->format('H:i') }} – {{ $event->ends_at->format('H:i') }}</div>
                        @if($event->location)
                            <div class="ev__m"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>{{ $event->location }}</div>
                        @endif
                        @if($reported)
                            <div class="ev__m reportnote"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/></svg>Reporté depuis le {{ $lastResched->old_starts_at->translatedFormat('j M Y') }} · {{ $lastResched->old_starts_at->format('H:i') }}</div>
                        @endif
                    </div>
                </div>
                <div class="tags">
                    <span class="tag tag--type" style="--tc:{{ $event->type->color }};color:var(--tc);background:color-mix(in srgb, var(--tc) 14%, transparent)">{{ $event->type->name }}</span>
                    <span class="tag {{ $statusTag[$st][0] }}">{{ $statusTag[$st][1] }}</span>
                    @if($reported)<span class="tag tag--reported"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/></svg>Reporté</span>@endif
                    <span class="tag tag--mode">{{ $event->qr_mode->label() }}</span>
                </div>
                <div class="ev__stat">
                    <div class="ev__count"><b>{{ $event->attendances_count }}</b><span>présent{{ $event->attendances_count > 1 ? 's' : '' }}</span></div>
                    <div class="ev__actions">
                        @if(in_array($st, ['clos', 'annule'], true))
                            <button type="button" class="mini" title="Voir le détail" onclick="event.preventDefault();event.stopPropagation();location.href='{{ route('admin.events.show', $event) }}'" aria-label="Voir le détail">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"/><circle cx="12" cy="12" r="3"/></svg>
                            </button>
                        @elseif($event->qr_mode->isTournant())
                            <button type="button" class="mini" title="Projeter le QR" onclick="event.preventDefault();event.stopPropagation();window.open('{{ route('admin.events.projection', $event) }}','_blank')" aria-label="Projeter le QR">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="4" width="20" height="13" rx="2"/><path d="M8 21h8M12 17v4"/></svg>
                            </button>
                        @else
                            <button type="button" class="mini" title="Imprimer le QR" onclick="event.preventDefault();event.stopPropagation();window.open('{{ route('admin.events.qr.print', $event) }}','_blank')" aria-label="Imprimer le QR">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M6 14h12v8H6z"/></svg>
                            </button>
                        @endif
                    </div>
                </div>
            </a>
        @empty
            <div class="empty" style="grid-column:1/-1">Aucun événement pour le moment.</div>
        @endforelse
    </div>

    <div id="ev-tablewrap" class="tablewrap" hidden>
        <div class="tscroll">
            <table class="dt" aria-label="Liste des événements">
                <thead>
                    <tr><th>Événement</th><th>Type</th><th>Date &amp; heure</th><th>Mode QR</th><th>Statut</th><th>Présents</th><th aria-label="Actions"></th></tr>
                </thead>
                <tbody id="evbody">
                    @forelse ($events as $event)
                        @php($st = $event->status()->value)
                        <tr class="evrow" data-status="{{ $st }}" data-search="{{ Str::lower($event->title.' '.$event->location.' '.$event->type->name) }}">
                            <td>
                                <a class="rowlink" href="{{ route('admin.events.show', $event) }}">{{ $event->title }}</a>
                                @if($event->location)<div class="person__e">{{ $event->location }}</div>@endif
                            </td>
                            <td><span class="tag tag--type" style="--tc:{{ $event->type->color }};color:var(--tc);background:color-mix(in srgb, var(--tc) 14%, transparent)">{{ $event->type->name }}</span></td>
                            <td class="mut">{{ $event->starts_at->translatedFormat('j M Y') }}<br><span class="hh">{{ $event->starts_at->format('H:i') }} – {{ $event->ends_at->format('H:i') }}</span></td>
                            <td><span class="tag tag--mode">{{ $event->qr_mode->label() }}</span></td>
                            <td><span class="tag {{ $statusTag[$st][0] }}">{{ $statusTag[$st][1] }}</span></td>
                            <td class="hh">{{ $event->attendances_count }}</td>
                            <td style="text-align:right"><a class="btn btn--ghost btn--sm" href="{{ route('admin.events.show', $event) }}">Ouvrir</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="empty">Aucun événement pour le moment.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <p class="empty" id="ev-noresult" hidden>Aucun événement ne correspond à votre recherche.</p>
@endsection

@push('scripts')
<script src="{{ asset('js/events-list.js') }}"></script>
@endpush
