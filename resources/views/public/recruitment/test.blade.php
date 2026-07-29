<!doctype html>
<html lang="fr">
<head><meta charset="utf-8"><title>{{ $test->titre }} — Test</title></head>
<body data-expires-at="{{ $candidature->expires_at->toIso8601String() }}">
<h1>{{ $test->titre }}</h1>
<div id="chrono"></div>
<form id="recrutement-form" method="POST" action="{{ route('public.recruitment.submit', ['test' => $test->slug, 'token' => $candidature->token]) }}">
    @csrf
    @foreach ($questions as $question)
        @php($reponse = $reponses->get($question->id))
        <fieldset data-question-id="{{ $question->id }}"
            data-answer-url="{{ route('public.recruitment.answer', ['test' => $test->slug, 'token' => $candidature->token, 'question' => $question->id]) }}">
            <legend>{{ $question->contexte['id'] }} — {{ $question->contexte['tiers'] }} ({{ $question->contexte['nature'] }})</legend>
            <p>Entité : {{ $question->contexte['entite'] }} — Pièce : {{ $question->contexte['piece'] }} — Date : {{ $question->contexte['date'] }}</p>
            <p>Montant HT : {{ number_format($question->contexte['montant_ht'], 0, ',', ' ') }} FCFA — TVA comptabilisée : {{ number_format($question->contexte['tva_comptabilisee'], 0, ',', ' ') }} FCFA — Retenue opérée : {{ number_format($question->contexte['retenue_operee'], 0, ',', ' ') }} FCFA</p>
            <p>{{ $question->contexte['informations'] }}</p>

            <label>Classement
                <select name="classement" class="js-champ">
                    <option value="">—</option>
                    @foreach (['Conforme', 'Modéré', 'Majeur', 'Critique'] as $option)
                        <option value="{{ $option }}" @selected($reponse?->classement_saisi === $option)>{{ $option }}</option>
                    @endforeach
                </select>
            </label>
            <label>Ajustement TVA (FCFA)
                <input type="number" name="ajustement_tva" class="js-champ" value="{{ $reponse?->ajustement_tva_saisi }}">
            </label>
            <label>Retenue attendue (FCFA)
                <input type="number" name="retenue_attendue" class="js-champ" value="{{ $reponse?->retenue_saisie }}">
            </label>
        </fieldset>
    @endforeach
    <button type="submit">Soumettre mes réponses</button>
</form>
<script>window.RECRUITMENT_CSRF = @json(csrf_token());</script>
<script src="{{ versioned_asset('js/recruitment.js') }}"></script>
</body>
</html>
