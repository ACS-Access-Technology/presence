@extends('layouts.admin', ['nav' => 'events'])

@section('title', $event->title)

@php
    use App\Enums\QrMode;
    $st = $event->status()->value;
    $statusTag = [
        'en_cours' => ['tag--live', 'En cours'],
        'a_venir' => ['tag--soon', 'À venir'],
        'clos' => ['tag--done', 'Clos'],
        'annule' => ['tag--cancelled', 'Annulé'],
    ][$st];
@endphp

@section('crumbs')
    <a href="{{ route('admin.events.index') }}">Événements</a><span>/</span><span class="cur">{{ $event->title }}</span>
@endsection

@section('topbar-actions')
    <button class="kbtn" onclick="Detail.cmdk(true)" aria-label="Rechercher un participant">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
        Rechercher <kbd>⌘K</kbd>
    </button>
@endsection

@section('content')
    @if (session('status'))
        <div class="flash-ok"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6 9 17l-5-5"/></svg>{{ session('status') }}</div>
    @endif

    @if ($event->isCancelled())
        <div class="evbanner evbanner--danger">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><circle cx="12" cy="12" r="9"/><path d="M15 9l-6 6M9 9l6 6"/></svg>
            <div>
                <div class="evbanner__t">Événement annulé</div>
                <div class="evbanner__d">Annulé le {{ $event->cancelled_at->translatedFormat('j M Y · H:i') }}@if($event->cancellation_reason) — {{ $event->cancellation_reason }}@endif. L'émargement est fermé ; les présences déjà enregistrées sont conservées.</div>
            </div>
            <form class="evbanner__act" method="POST" action="{{ route('admin.events.uncancel', $event) }}">
                @csrf
                <button type="submit" class="btn btn--ghost btn--sm">Réactiver</button>
            </form>
        </div>
    @elseif ($lastReschedule)
        <div class="evbanner evbanner--warn">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/></svg>
            <div>
                <div class="evbanner__t">Événement reporté</div>
                <div class="evbanner__d">Ancien créneau : {{ $lastReschedule->old_starts_at->translatedFormat('j M Y · H:i') }} → {{ $lastReschedule->old_ends_at->format('H:i') }}@if($lastReschedule->reason) — {{ $lastReschedule->reason }}@endif.</div>
            </div>
        </div>
    @endif

    <div class="pagehead">
        <div>
            <h1>{{ $event->title }}</h1>
            <p>
                {{ $event->starts_at->translatedFormat('j M Y') }} · {{ $event->starts_at->format('H:i') }} – {{ $event->ends_at->format('H:i') }}@if($event->location) · {{ $event->location }}@endif
                &nbsp;<span class="tag {{ $statusTag[0] }}">{{ $statusTag[1] }}</span>
                <span class="tag tag--type" style="--tc:{{ $event->type->color }};color:var(--tc);background:color-mix(in srgb, var(--tc) 14%, transparent)">{{ $event->type->name }}</span>
            </p>
        </div>
        <div style="margin-left:auto;display:flex;gap:9px;flex-wrap:wrap">
            <div class="export-group" role="group" aria-label="Exporter la liste de présence">
                <button type="button" class="btn btn--ghost" onclick="Detail.exportAs('csv')" title="Export filtré selon la liste affichée">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M12 3v12m0 0 4-4m-4 4-4-4M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/></svg>
                    CSV
                </button>
                <button type="button" class="btn btn--ghost" onclick="Detail.exportAs('xlsx')" title="Export filtré selon la liste affichée">Excel</button>
                <button type="button" class="btn btn--ghost" onclick="Detail.exportAs('pdf')" title="Export filtré selon la liste affichée">PDF</button>
            </div>
            @if($event->qr_mode === QrMode::Tournant)
                <a class="btn btn--primary" href="{{ route('admin.events.projection', $event) }}" target="_blank" rel="noopener">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg>
                    Projeter le QR
                </a>
            @else
                <a class="btn btn--primary" href="{{ route('admin.events.qr.print', $event) }}" target="_blank" rel="noopener">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M6 14h12v8H6z"/></svg>
                    Imprimer le QR
                </a>
            @endif
            <button type="button" class="btn btn--ghost" onclick="Detail.open('m-edit')">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M12 20h9M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                Modifier
            </button>
            @unless ($event->isCancelled())
                <button type="button" class="btn btn--ghost" onclick="Detail.open('m-reschedule')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/></svg>
                    Reporter
                </button>
                <button type="button" class="btn btn--ghost" onclick="Detail.open('m-cancel')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><circle cx="12" cy="12" r="9"/><path d="M15 9l-6 6M9 9l6 6"/></svg>
                    Annuler
                </button>
            @endunless
        </div>
    </div>

    @if ($siblingSeances->isNotEmpty())
        <div class="notice" style="margin-top:-4px;margin-bottom:18px">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
            <span>
                <strong>Séance {{ $event->series_position }}</strong> d'une série de {{ $siblingSeances->count() + 1 }}.
                Autres séances :
                @foreach ($siblingSeances as $s)
                    <a class="linkbtn" href="{{ route('admin.events.show', $s) }}">{{ $s->starts_at->translatedFormat('j M Y') }} ({{ $s->attendances_count }} présent{{ $s->attendances_count > 1 ? 's' : '' }})</a>@if(!$loop->last), @endif
                @endforeach
            </span>
        </div>
    @endif

    <div class="tabs" role="tablist" aria-label="Sections de l'événement">
        <button class="tab" role="tab" id="tab-liste" aria-selected="true" onclick="Detail.tab('liste')">
            Liste de présence <span class="cnt" id="cnt-liste">{{ $stats['total'] }}</span>
        </button>
        <button class="tab" role="tab" id="tab-stats" aria-selected="false" onclick="Detail.tab('stats')">Statistiques</button>
        <button class="tab" role="tab" id="tab-cr" aria-selected="false" onclick="Detail.tab('cr')">
            Compte-rendu <span class="cnt" id="cnt-cr">{{ count($documents) + count($photos) }}</span>
        </button>
    </div>

    {{-- ===== Onglet liste ===== --}}
    <section class="panel" id="panel-liste" role="tabpanel" aria-labelledby="tab-liste">
        <div class="toolbar">
            <label class="searchbar">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
                <input id="pfilter" type="search" placeholder="Filtrer (nom, entité, direction…)" aria-label="Filtrer la liste" oninput="Detail.render()">
            </label>
            <div class="chips" role="group" aria-label="Filtrer">
                <button class="chip" aria-pressed="true" data-f="all" onclick="Detail.chip('all',this)">Tous</button>
                <button class="chip" aria-pressed="false" data-f="new" onclick="Detail.chip('new',this)">Nouveaux</button>
                <button class="chip" aria-pressed="false" data-f="rec" onclick="Detail.chip('rec',this)">Récurrents</button>
            </div>
            @if($event->isOpenForCheckIn())
                <button class="btn btn--ghost btn--sm" onclick="Detail.manual(true)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/></svg>
                    Présence manuelle
                </button>
            @endif
        </div>

        <div class="tablewrap">
            <div class="tscroll">
                <table class="dt" aria-label="Liste de présence">
                    <thead>
                        <tr>
                            <th class="sortable" data-sort="name" aria-sort="none" onclick="Detail.sort('name',this)">Participant <span class="ar">↕</span></th>
                            <th class="sortable" data-sort="company" aria-sort="none" onclick="Detail.sort('company',this)">Entité / Entreprise <span class="ar">↕</span></th>
                            <th class="sortable" data-sort="direction" aria-sort="none" onclick="Detail.sort('direction',this)">Direction <span class="ar">↕</span></th>
                            <th class="sortable" data-sort="time_sort" aria-sort="descending" onclick="Detail.sort('time_sort',this)">Heure <span class="ar">↕</span></th>
                            <th class="sortable" data-sort="left" aria-sort="none" onclick="Detail.sort('left',this)">Départ <span class="ar">↕</span></th>
                            <th>Signature</th>
                            <th aria-label="Actions"></th>
                        </tr>
                    </thead>
                    <tbody id="pbody"></tbody>
                </table>
            </div>
            <div class="tfooter">
                <span class="live-dot" id="livecount">{{ $stats['total'] }} présents</span>
                <span class="mut">Mise à jour automatique</span>
            </div>
        </div>
    </section>

    {{-- ===== Onglet stats ===== --}}
    <section class="panel" id="panel-stats" role="tabpanel" aria-labelledby="tab-stats" hidden>
        <div class="kpis">
            <div class="kpi"><span class="ic" style="background:var(--success-soft);color:var(--success)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="9" cy="8" r="4"/><path d="M2 21c0-4 3-6 7-6M16 11l2 2 4-4"/></svg></span><div><div class="kpi__val" id="kpi-total">{{ $stats['total'] }}</div><div class="kpi__lbl">Présents</div></div></div>
            <div class="kpi"><span class="ic" style="background:var(--info-soft);color:var(--info)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 21V7l9-4 9 4v14"/><path d="M9 21v-6h6v6"/></svg></span><div><div class="kpi__val">{{ $stats['companies'] }}</div><div class="kpi__lbl">Entreprises</div></div></div>
            <div class="kpi"><span class="ic" style="background:var(--brand-orange-soft);color:var(--brand-orange)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 5v14M5 12h14"/></svg></span><div><div class="kpi__val">{{ $stats['newcomers'] }}</div><div class="kpi__lbl">Nouveaux</div></div></div>
            <div class="kpi"><span class="ic" style="background:var(--accent-soft);color:var(--accent)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/></svg></span><div><div class="kpi__val">{{ $stats['recurrent'] }}</div><div class="kpi__lbl">Récurrents</div></div></div>
        </div>

        <div class="grid2" style="gap:18px">
            @foreach (['by_company' => 'Répartition par entreprise', 'by_direction' => 'Répartition par direction'] as $key => $label)
                <div class="tablewrap" style="padding:16px">
                    <h3 style="margin:0 0 12px;font-size:1rem">{{ $label }}</h3>
                    @php($max = collect($stats[$key])->max() ?: 1)
                    @forelse ($stats[$key] as $name => $count)
                        <div style="display:grid;grid-template-columns:150px 1fr auto;align-items:center;gap:10px;margin-bottom:8px">
                            <span class="mut" style="font-size:.83rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ $name ?: '—' }}</span>
                            <span style="height:9px;border-radius:5px;background:var(--accent);width:{{ round($count / $max * 100) }}%;min-width:6px"></span>
                            <b style="font-variant-numeric:tabular-nums">{{ $count }}</b>
                        </div>
                    @empty
                        <p class="mut" style="font-size:.85rem">Aucune donnée.</p>
                    @endforelse
                </div>
            @endforeach
        </div>
    </section>

    {{-- ===== Onglet compte-rendu ===== --}}
    <section class="panel" id="panel-cr" role="tabpanel" aria-labelledby="tab-cr" hidden>
        @if (! $event->hasStarted())
            <div class="cr-locked">
                <div class="cr-locked__ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg></div>
                <h3>Le compte-rendu s'ouvrira après le début de l'événement</h3>
                <p>Cette activité n'a pas encore eu lieu. Une fois qu'elle aura démarré, vous pourrez rédiger le compte-rendu, joindre des documents et ajouter les photos — le tout alimentera le portfolio.</p>
            </div>
        @else
            <div class="cr-banner">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><circle cx="12" cy="12" r="9"/><path d="M12 8v4l3 2"/></svg>
                <div><b>{{ $event->status()->value === 'clos' ? 'Activité clôturée.' : 'Activité en cours.' }}</b> <span>Rédigez le compte-rendu, joignez documents et photos — ils sont conservés et visibles dans le portfolio.</span></div>
            </div>

            <div class="cr-grid">
                <div class="cr-card">
                    <div class="cr-card__hd"><h3>Compte-rendu de l'activité</h3><span class="cr-savestate" id="cr-save"></span></div>
                    <div class="cr-toolbar">
                        <button type="button" title="Gras" onclick="Report.wrap('**')"><b>B</b></button>
                        <button type="button" title="Italique" onclick="Report.wrap('_')"><i>I</i></button>
                        <button type="button" title="Titre" onclick="Report.prefix('## ')">H</button>
                        <button type="button" title="Liste à puces" onclick="Report.prefix('- ')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg></button>
                        <button type="button" title="Case à cocher" onclick="Report.prefix('- [ ] ')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><rect x="3" y="3" width="18" height="18" rx="3"/><path d="M8 12l3 3 5-6"/></svg></button>
                    </div>
                    <textarea class="cr-textarea" id="cr-text" placeholder="Objectifs, déroulé, décisions prises, actions à suivre…">{{ $report->body ?? '' }}</textarea>
                    <div class="cr-chars"><span id="cr-count">0</span> caractères</div>
                    <div style="margin-top:12px">
                        <button class="btn btn--primary" id="cr-savebtn" onclick="Report.save()">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M20 6 9 17l-5-5"/></svg>
                            Enregistrer le compte-rendu
                        </button>
                    </div>
                </div>

                <div class="cr-card">
                    <div class="cr-card__hd"><div><h3>Documents <span class="cnt" id="doc-cnt">0</span></h3><p class="cr-card__sub">PDF, Word, Excel, présentations…</p></div></div>
                    <div class="doc-list" id="doc-list"></div>
                    <label class="dropzone" id="doc-drop">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/></svg>
                        Glissez des fichiers ou <b>parcourez</b>
                        <input type="file" id="doc-input" multiple hidden accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.csv,.txt">
                    </label>
                </div>

                <div class="cr-card">
                    <div class="cr-card__hd"><div><h3>Photos <span class="cnt" id="photo-cnt">0</span></h3><p class="cr-card__sub">Composent la galerie du portfolio</p></div></div>
                    <div class="photo-grid" id="photo-grid"></div>
                    <label class="dropzone" id="photo-drop">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.8"/><path d="M21 15l-5-5L5 21"/></svg>
                        Glissez des photos ou <b>parcourez</b>
                        <input type="file" id="photo-input" multiple hidden accept="image/*">
                    </label>
                </div>
            </div>
        @endif
    </section>

    @include('admin.events._detail_modals')
@endsection

@push('scripts')
<script>
    window.EVENT = {
        id: {{ $event->id }},
        csrf: @json(csrf_token()),
        isOpen: @json($event->isOpenForCheckIn()),
        urls: {
            feed: @json(route('admin.events.attendances.feed', $event)),
            manual: @json(route('admin.events.attendances.manual', $event)),
            exportCsv: @json(route('admin.events.attendances.export', $event)),
            exportXlsx: @json(route('admin.events.attendances.export.xlsx', $event)),
            exportPdf: @json(route('admin.events.attendances.export.pdf', $event)),
        },
        rows: @json($rows),
        report: {
            canEdit: @json($event->hasStarted()),
            urls: {
                saveText: @json(route('admin.events.report.save', $event)),
                uploadDocuments: @json(route('admin.events.report.documents.store', $event)),
                uploadPhotos: @json(route('admin.events.report.photos.store', $event)),
            },
            documents: @json($documents),
            photos: @json($photos),
        },
    };
</script>
<script src="{{ versioned_asset('js/event-detail.js') }}"></script>
<script src="{{ versioned_asset('js/report.js') }}"></script>
@endpush
