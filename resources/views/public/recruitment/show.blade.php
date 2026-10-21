@extends('layouts.recruitment-public')

@section('title', $test->titre)

@section('content')
<div class="rec-shell">
    <div class="rec-card">
        <h1 class="rec-title">{{ $test->titre }}</h1>
        <p class="rec-lede">{{ $test->instructions }}</p>
        <div class="rec-meta">
            <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="width:15px;height:15px;vertical-align:-2px;margin-right:4px"><circle cx="12" cy="12" r="9"/><path d="M12 8v4l3 2"/></svg>Durée : <strong>{{ $test->duree_minutes }} minutes</strong></span>
        </div>

        <div class="rec-warning">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true"><path d="M12 9v4M12 17h.01"/><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/></svg>
            <p><strong>Attention</strong> : préparez {{ $test->duree_minutes }} minutes devant vous, sans être dérangé(e), avant de commencer. Une fois le test lancé, si vous changez de page, fermez cet onglet ou rechargez la page, l'exercice sera automatiquement clôturé et vos réponses jusque-là seront définitives — aucune reprise possible.</p>
        </div>

        <form method="POST" action="{{ route('public.recruitment.store', $test->slug) }}">
            @csrf
            <div class="rec-form-grid">
                <div class="field">
                    <label for="nom">Nom <span class="req">*</span></label>
                    <input class="control" id="nom" name="nom" type="text" value="{{ old('nom') }}" required autocomplete="family-name">
                </div>
                <div class="field">
                    <label for="prenom">Prénom <span class="req">*</span></label>
                    <input class="control" id="prenom" name="prenom" type="text" value="{{ old('prenom') }}" required autocomplete="given-name">
                </div>
                <div class="field {{ $errors->has('email') ? 'invalid' : '' }}">
                    <label for="email">Email <span class="req">*</span></label>
                    <input class="control" id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="email" aria-invalid="{{ $errors->has('email') ? 'true' : 'false' }}">
                    @if ($errors->has('email'))
                        <div class="err-msg" style="display:block">{{ $errors->first('email') }}</div>
                    @endif
                </div>
                <div class="field">
                    <label for="telephone">Téléphone <span class="opt">(optionnel)</span></label>
                    <input class="control" id="telephone" name="telephone" type="tel" value="{{ old('telephone') }}" autocomplete="tel">
                </div>
            </div>
            <button type="submit" class="btn btn--primary btn--block btn--lg" style="margin-top:22px">Commencer le test</button>
        </form>
    </div>
</div>
@endsection
