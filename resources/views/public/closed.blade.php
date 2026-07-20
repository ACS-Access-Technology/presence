@extends('layouts.public')

@section('content')
    <section class="screen">
        <div class="errscreen">
            <div class="errscreen__ic">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
            </div>
            @if ($reason === 'cancelled')
                <h1>Événement annulé</h1>
                <p>Cet événement a été annulé. L'émargement n'est pas possible.</p>
            @elseif ($reason === 'not_started')
                <h1>Émargement pas encore ouvert</h1>
                <p>L'émargement ouvrira au début de l'événement, le {{ $event->starts_at->translatedFormat('j M Y à H:i') }}.</p>
            @else
                <h1>Émargement clôturé</h1>
                <p>La période d'émargement de cet événement est terminée.</p>
            @endif
        </div>
    </section>
@endsection
