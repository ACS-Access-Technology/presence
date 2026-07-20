@extends('layouts.admin', ['nav' => 'portfolio'])

@section('title', 'Portfolio')

@section('content')
    <div class="pagehead">
        <div>
            <h1>Portfolio des activités</h1>
            <p>La mémoire des activités d'ACS Groupe : comptes-rendus, documents et photos réunis pour chaque événement documenté.</p>
        </div>
    </div>

    <div class="kpis" style="grid-template-columns:repeat(3,1fr)">
        <div class="kpi"><span class="ic" style="background:var(--accent-soft);color:var(--accent)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.8"/><path d="M21 15l-5-5L5 21"/></svg></span><div><div class="kpi__val">{{ $kpis['activities'] }}</div><div class="kpi__lbl">Activités documentées</div></div></div>
        <div class="kpi"><span class="ic" style="background:var(--type-atelier-bg);color:var(--type-atelier)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.8"/><path d="M21 15l-5-5L5 21"/></svg></span><div><div class="kpi__val">{{ $kpis['photos'] }}</div><div class="kpi__lbl">Photos</div></div></div>
        <div class="kpi"><span class="ic" style="background:var(--info-soft);color:var(--info)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/></svg></span><div><div class="kpi__val">{{ $kpis['documents'] }}</div><div class="kpi__lbl">Documents</div></div></div>
    </div>

    <div class="toolbar">
        <label class="searchbar">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
            <input id="pfsearch" type="search" placeholder="Rechercher une activité (titre, lieu, type…)" aria-label="Rechercher" oninput="Portfolio.filter()">
        </label>
        <div class="chips" role="group" aria-label="Filtrer par type">
            <button class="chip" aria-pressed="true" data-t="all" onclick="Portfolio.type('all',this)">Tous les types</button>
            @foreach ($types as $t)
                <button class="chip" aria-pressed="false" data-t="{{ $t->name }}" onclick="Portfolio.type(@js($t->name),this)"><i style="width:9px;height:9px;border-radius:50%;display:inline-block;background:{{ $t->color }}"></i>{{ $t->name }}</button>
            @endforeach
        </div>
    </div>

    @if (count($cards) === 0)
        <div class="pf-empty">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.8"/><path d="M21 15l-5-5L5 21"/></svg>
            <h3>Aucune activité documentée</h3>
            <p>Les comptes-rendus, documents et photos ajoutés à vos événements apparaîtront ici.</p>
        </div>
    @else
        <div class="pf-grid" id="pf-grid">
            @foreach ($cards as $c)
                <a class="pf" href="{{ $c['url'] }}" data-type="{{ $c['type'] }}" data-search="{{ $c['search'] }}">
                    <div class="pf__cover {{ $c['cover'] ? '' : 'pf__cover--empty' }}" @if($c['cover']) style="background-image:url('{{ $c['cover'] }}')" @endif>
                        <span class="pf__type"><span class="tag tag--type" style="--tc:{{ $c['type_color'] }};color:var(--tc);background:color-mix(in srgb, var(--tc) 16%, transparent)">{{ $c['type'] }}</span></span>
                        <span class="pf__badges">
                            @if($c['photos_count'])<span class="pf__badge"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.8"/><path d="M21 15l-5-5L5 21"/></svg>{{ $c['photos_count'] }}</span>@endif
                            @if($c['documents_count'])<span class="pf__badge"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/></svg>{{ $c['documents_count'] }}</span>@endif
                        </span>
                    </div>
                    <div class="pf__body">
                        <div class="pf__meta">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                            {{ $c['date'] }}@if($c['location']) · {{ $c['location'] }}@endif
                        </div>
                        <div class="pf__t">{{ $c['title'] }}</div>
                        @if($c['excerpt'])<p class="pf__ex">{{ $c['excerpt'] }}</p>@endif
                        <div class="pf__foot">Voir le compte-rendu <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 6l6 6-6 6"/></svg></div>
                    </div>
                </a>
            @endforeach
        </div>
        <div class="pf-empty" id="pf-noresult" hidden><h3>Aucune activité ne correspond</h3></div>
    @endif
@endsection

@push('scripts')
<script src="{{ versioned_asset('js/portfolio.js') }}"></script>
@endpush
