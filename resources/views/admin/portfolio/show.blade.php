@extends('layouts.admin', ['nav' => 'portfolio'])

@section('title', $event->title)

@section('crumbs')
    <a href="{{ route('admin.portfolio') }}">Portfolio</a><span>/</span><span class="cur">{{ $event->title }}</span>
@endsection

@section('content')
    <div class="pagehead">
        <div>
            <h1>{{ $event->title }}</h1>
            <p>
                <span class="tag tag--type" style="--tc:{{ $event->type->color }};color:var(--tc);background:color-mix(in srgb, var(--tc) 16%, transparent)">{{ $event->type->name }}</span>
                {{ $event->starts_at->translatedFormat('j M Y') }}@if($event->location) · {{ $event->location }}@endif
            </p>
        </div>
        <a class="btn btn--ghost" href="{{ route('admin.events.show', $event) }}">
            Voir le contenu de l'activité
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
        </a>
    </div>

    @if (count($photos) === 0)
        <div class="pf-empty">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.8"/><path d="M21 15l-5-5L5 21"/></svg>
            <h3>Aucune photo pour cette activité</h3>
            <p>Les photos ajoutées depuis la fiche de l'événement apparaîtront ici.</p>
        </div>
    @else
        <div class="pfg-grid">
            @foreach ($photos as $i => $p)
                <button type="button" class="pfg-cell" onclick="PortfolioGallery.open({{ $i }})">
                    <img src="{{ $p['url'] }}" alt="Photo de l'activité" loading="lazy">
                </button>
            @endforeach
        </div>
    @endif

    <div class="pfg-lightbox" id="pfg-lightbox" hidden role="dialog" aria-modal="true" aria-label="Photo de l'activité">
        <button type="button" class="pfg-lightbox__backdrop" aria-label="Fermer" onclick="PortfolioGallery.close()"></button>
        <button type="button" class="pfg-lightbox__close" aria-label="Fermer" onclick="PortfolioGallery.close()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18M6 6l12 12"/></svg>
        </button>
        <button type="button" class="pfg-lightbox__nav pfg-lightbox__nav--prev" aria-label="Photo précédente" onclick="PortfolioGallery.prev()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6"/></svg>
        </button>
        <img class="pfg-lightbox__img" id="pfg-lightbox-img" src="" alt="Photo de l'activité">
        <button type="button" class="pfg-lightbox__nav pfg-lightbox__nav--next" aria-label="Photo suivante" onclick="PortfolioGallery.next()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 6l6 6-6 6"/></svg>
        </button>
        <div class="pfg-lightbox__count" id="pfg-lightbox-count"></div>
    </div>
@endsection

@push('scripts')
<script>
    window.PORTFOLIO_GALLERY = { photos: @json($photos) };
</script>
<script src="{{ versioned_asset('js/portfolio-gallery.js') }}"></script>
@endpush
