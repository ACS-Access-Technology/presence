@extends('layouts.recruitment-public')

@section('title', $test->titre.' — Test')

@section('body-attributes')data-expires-at="{{ $candidature->expires_at->toIso8601String() }}"@endsection

@section('content')
<div class="rec-shell rec-shell--wide">
    <div id="chrono" class="rec-chrono" role="status" aria-live="polite">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>
        <span></span>
    </div>

    <div class="rec-wizard-head">
        <h2 class="rec-wizard-title">{{ $test->titre }}</h2>
        @if ($test->instructions)
            <details class="rec-instructions" open>
                <summary>Consignes de l'exercice</summary>
                <p>{{ $test->instructions }}</p>
            </details>
        @endif
        <div class="rec-stepper">
            <div class="rec-stepper__bar"><div class="rec-stepper__fill" id="stepFill"></div></div>
            <div class="rec-stepper__label" id="stepLabel"></div>
        </div>
    </div>

    <form id="recrutement-form" method="POST" action="{{ route('public.recruitment.submit', ['test' => $test->slug, 'token' => $candidature->token]) }}">
        @csrf
        @foreach ($questions as $index => $question)
            @php($reponse = $reponses->get($question->id))
            <fieldset class="rec-q" data-step="{{ $index + 1 }}" data-question-id="{{ $question->id }}"
                data-answer-url="{{ route('public.recruitment.answer', ['test' => $test->slug, 'token' => $candidature->token, 'question' => $question->id]) }}">
                <div class="rec-q__head">
                    <span class="rec-q__id">{{ $question->contexte['id'] }}</span>
                    <span class="rec-q__title">{{ $question->contexte['tiers'] }} — {{ $question->contexte['nature'] }}</span>
                </div>
                <p class="rec-q__sub">Entité : {{ $question->contexte['entite'] }} · Pièce : {{ $question->contexte['piece'] }} · Date : {{ $question->contexte['date'] }}</p>
                <div class="rec-q__amounts">
                    <span>Montant HT : <b>{{ number_format($question->contexte['montant_ht'], 0, ',', ' ') }} FCFA</b></span>
                    <span>TVA comptabilisée : <b>{{ number_format($question->contexte['tva_comptabilisee'], 0, ',', ' ') }} FCFA</b></span>
                    <span>Retenue opérée : <b>{{ number_format($question->contexte['retenue_operee'], 0, ',', ' ') }} FCFA</b></span>
                </div>
                <p class="rec-q__info">{{ $question->contexte['informations'] }}</p>

                <div class="rec-q__fields">
                    <div class="field">
                        <label for="classement-{{ $question->id }}">Classement</label>
                        <select class="control js-champ" id="classement-{{ $question->id }}" name="classement">
                            <option value="">—</option>
                            @foreach (['Conforme', 'Modéré', 'Majeur', 'Critique'] as $option)
                                <option value="{{ $option }}" @selected($reponse?->classement_saisi === $option)>{{ $option }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="ajustement-{{ $question->id }}">Ajustement TVA (FCFA)</label>
                        <input class="control js-champ" id="ajustement-{{ $question->id }}" type="number" name="ajustement_tva" value="{{ $reponse?->ajustement_tva_saisi }}">
                    </div>
                    <div class="field">
                        <label for="retenue-{{ $question->id }}">Retenue attendue (FCFA)</label>
                        <input class="control js-champ" id="retenue-{{ $question->id }}" type="number" name="retenue_attendue" value="{{ $reponse?->retenue_saisie }}">
                    </div>
                </div>
            </fieldset>
        @endforeach

        <div class="rec-wizard-nav">
            <button type="button" class="btn btn--ghost" id="stepPrev">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M15 18l-6-6 6-6"/></svg>
                Précédent
            </button>
            <button type="button" class="btn btn--primary" id="stepNext">
                Suivant
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M9 18l6-6-6-6"/></svg>
            </button>
            <button type="submit" class="btn btn--primary btn--lg" id="stepSubmit">Soumettre mes réponses</button>
        </div>
    </form>
</div>

<script>window.RECRUITMENT_CSRF = @json(csrf_token());</script>
<script src="{{ versioned_asset('js/recruitment.js') }}"></script>
@endsection
