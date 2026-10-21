@extends('layouts.recruitment-public')

@section('title', 'Session terminée')

@section('content')
<div class="rec-shell">
    <div class="rec-status rec-status--closed">
        <div class="rec-status__ic">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>
        </div>
        <h1>Cette session de test n'est plus disponible</h1>
        <p>Le temps imparti est écoulé, vous avez quitté ou rechargé la page en cours de test, ou vos réponses ont déjà été transmises. Contactez l'équipe recrutement si vous pensez qu'il s'agit d'une erreur.</p>
    </div>
</div>
@endsection
