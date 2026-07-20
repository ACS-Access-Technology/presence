@extends('layouts.admin')

@section('title', 'Mon compte')

@section('crumbs')
    <span class="cur">Mon compte</span>
@endsection

@section('content')
    <div class="pagehead">
        <div>
            <h1>Mon compte</h1>
            <p>{{ auth()->user()->name }} · {{ auth()->user()->email }} · {{ auth()->user()->role->label() }}</p>
        </div>
    </div>

    @if (session('status'))
        <div class="flash-ok"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6 9 17l-5-5"/></svg>{{ session('status') }}</div>
    @endif

    <div class="card">
        <div class="card__hd">
            <div><h2>Changer mon mot de passe</h2><p>Choisis un mot de passe d'au moins 8 caractères.</p></div>
        </div>
        <div class="card__body">
            <form method="POST" action="{{ route('admin.profile.password') }}" style="max-width:420px">
                @csrf
                @method('PATCH')
                <div class="field {{ $errors->has('current_password') ? 'invalid' : '' }}">
                    <label for="current_password">Mot de passe actuel <span class="req">*</span></label>
                    <input class="control" id="current_password" name="current_password" type="password" required autocomplete="current-password" aria-invalid="{{ $errors->has('current_password') ? 'true' : 'false' }}">
                    <div class="err-msg">{{ $errors->first('current_password') }}</div>
                </div>
                <div class="field {{ $errors->has('password') ? 'invalid' : '' }}">
                    <label for="password">Nouveau mot de passe <span class="req">*</span></label>
                    <input class="control" id="password" name="password" type="password" required autocomplete="new-password" aria-invalid="{{ $errors->has('password') ? 'true' : 'false' }}">
                    <div class="err-msg">{{ $errors->first('password') }}</div>
                </div>
                <div class="field">
                    <label for="password_confirmation">Confirmer le nouveau mot de passe <span class="req">*</span></label>
                    <input class="control" id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password">
                </div>
                <button type="submit" class="btn btn--primary">Enregistrer le mot de passe</button>
            </form>
        </div>
    </div>
@endsection
