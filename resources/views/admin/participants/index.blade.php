@extends('layouts.admin', ['nav' => 'participants'])

@section('title', 'Participants')

@section('content')
    <div class="pagehead">
        <div>
            <h1>Annuaire des participants</h1>
            <p>Recherchez une personne pour voir tous les événements ACS auxquels elle a participé, et ses statistiques.</p>
        </div>
    </div>

    <div class="toolbar">
        <label class="searchbar">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
            <input id="psearch" type="search" placeholder="Rechercher par nom, entreprise, direction…" aria-label="Rechercher une personne" oninput="Annuaire.filter()">
        </label>
    </div>

    <div class="dir-head">
        <h2>Tous les participants</h2>
        <span class="n" id="dir-count">{{ count($people) }} personne{{ count($people) > 1 ? 's' : '' }}</span>
    </div>

    @if (count($people) === 0)
        <div class="pf-empty">
            <h3>Aucun participant pour le moment</h3>
            <p>Les personnes ayant émargé à un événement apparaîtront ici.</p>
        </div>
    @else
        <div class="pcard-grid" id="pcard-grid">
            @foreach ($people as $p)
                <a class="pcard" href="{{ $p['url'] }}" data-search="{{ $p['search'] }}">
                    <div class="pcard__top">
                        <span class="pcard__av" style="background:{{ $p['color'] }}">{{ $p['initials'] }}</span>
                        <div>
                            <div class="pcard__n">{{ $p['name'] }}@if($p['is_staff'])<span class="badge-new" style="color:var(--accent);background:var(--accent-soft)">ACS</span>@endif</div>
                            <div class="pcard__d">{{ $p['detail'] ?: '—' }}</div>
                        </div>
                    </div>
                    <div class="pcard__stats">
                        <div class="pcard__stat"><b>{{ $p['attendances'] }}</b><span>présence{{ $p['attendances'] > 1 ? 's' : '' }}</span></div>
                        <div class="pcard__stat"><b style="font-size:.92rem">{{ $p['last'] }}</b><span>dernière</span></div>
                    </div>
                </a>
            @endforeach
        </div>
        <div class="pf-empty" id="p-noresult" hidden><h3>Aucun participant ne correspond</h3></div>
    @endif
@endsection

@push('scripts')
<script src="{{ asset('js/participants.js') }}"></script>
@endpush
