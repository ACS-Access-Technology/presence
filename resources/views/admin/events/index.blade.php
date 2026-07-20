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
        <a class="btn btn--primary" href="#" onclick="return false" title="À venir">
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
            <button aria-pressed="true" data-s="all" onclick="EvList.status('all',this)">Tous</button>
            <button aria-pressed="false" data-s="en_cours" onclick="EvList.status('en_cours',this)">En cours</button>
            <button aria-pressed="false" data-s="a_venir" onclick="EvList.status('a_venir',this)">À venir</button>
            <button aria-pressed="false" data-s="clos" onclick="EvList.status('clos',this)">Clos</button>
            <button aria-pressed="false" data-s="annule" onclick="EvList.status('annule',this)">Annulés</button>
        </div>
    </div>

    <div class="tablewrap">
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
