@extends('layouts.admin', ['nav' => 'participants'])

@section('title', $person->fullName())

@section('crumbs')
    <a href="{{ route('admin.participants.index') }}">Participants</a><span>/</span><span class="cur">{{ $person->fullName() }}</span>
@endsection

@section('content')
    <a class="back" href="{{ route('admin.participants.index') }}">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
        Retour à l'annuaire
    </a>

    <div class="profile">
        <div class="profile__top">
            <span class="profile__av" style="background:{{ $color }}">{{ $initials }}</span>
            <div class="profile__id">
                <h2>{{ $person->fullName() }}@if($person->is_staff)<span class="badge-new" style="color:var(--accent);background:var(--accent-soft);vertical-align:middle">Personnel ACS</span>@endif</h2>
                <div class="profile__badges">
                    @if($person->position)<span class="chip-i"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>{{ $person->position }}</span>@endif
                    @if($person->company)<span class="chip-i"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 21V7l9-4 9 4v14"/><path d="M9 21v-6h6v6"/></svg>{{ $person->company }}</span>@endif
                    @if($person->direction)<span class="chip-i"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 9l9-6 9 6v11a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1Z"/></svg>{{ $person->direction }}@if($person->service) · {{ $person->service }}@endif</span>@endif
                    <span class="chip-i"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>{{ $person->email }}</span>
                    @if($person->phone)<span class="chip-i"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3 19.5 19.5 0 0 1-6-6 19.8 19.8 0 0 1-3-8.6A2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .3 1.9.6 2.8a2 2 0 0 1-.5 2.1L8 9.6a16 16 0 0 0 6 6l1-1.2a2 2 0 0 1 2.1-.5c.9.3 1.8.5 2.8.6a2 2 0 0 1 1.7 2Z"/></svg>{{ $person->phone }}</span>@endif
                </div>
            </div>
        </div>
    </div>

    <div class="kpis">
        <div class="kpi"><span class="ic" style="background:var(--accent-soft);color:var(--accent)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg></span><div><div class="kpi__val">{{ $stats['events'] }}</div><div class="kpi__lbl">Événements</div></div></div>
        <div class="kpi"><span class="ic" style="background:var(--info-soft);color:var(--info)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 21V7l9-4 9 4v14"/></svg></span><div><div class="kpi__val">{{ $stats['companies'] }}</div><div class="kpi__lbl">Entreprises</div></div></div>
        <div class="kpi"><span class="ic" style="background:var(--surface-3);color:var(--muted)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></span><div><div class="kpi__val" style="font-size:1.05rem">{{ $stats['first'] }}</div><div class="kpi__lbl">Première</div></div></div>
        <div class="kpi"><span class="ic" style="background:var(--success-soft);color:var(--success)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></span><div><div class="kpi__val" style="font-size:1.05rem">{{ $stats['last'] }}</div><div class="kpi__lbl">Dernière</div></div></div>
    </div>

    <div class="tablewrap">
        <div class="tscroll">
            <table class="dt" aria-label="Historique des présences">
                <thead><tr><th>Événement</th><th>Type</th><th>Date</th><th>Arrivée</th><th>Départ</th></tr></thead>
                <tbody>
                    @forelse ($history as $h)
                        <tr>
                            <td><a class="rowlink" href="{{ $h['event_url'] }}">{{ $h['event_title'] }}</a>@if($h['manual'])<span class="badge-manual">Manuelle</span>@endif</td>
                            <td><span class="tag tag--type" style="--tc:{{ $h['type_color'] }};color:var(--tc);background:color-mix(in srgb, var(--tc) 14%, transparent)">{{ $h['type'] }}</span></td>
                            <td class="mut">{{ $h['date'] }}</td>
                            <td class="hh">{{ $h['time'] }}</td>
                            <td>@if($h['left'])<span class="badge-left">Parti {{ $h['left'] }}</span>@else<span class="mut">—</span>@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="empty">Aucune présence enregistrée.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
