@extends('layouts.public')

@section('content')
    <section class="screen">
        @if (! $available)
            <div class="errscreen">
                <div class="errscreen__ic">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                </div>
                <h1>Pas encore disponible</h1>
                <p>Le formulaire d'avis s'ouvrira à la fin de l'événement.</p>
            </div>
        @elseif ($attendance->feedback !== null)
            <div class="errscreen">
                <div class="errscreen__ic">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
                </div>
                <h1>Merci !</h1>
                <p>Votre avis sur « {{ $event->title }} » a bien été enregistré.</p>
            </div>
        @else
            <div class="section-label">Votre avis</div>
            <h1 style="margin:0 0 4px">{{ $event->title }}</h1>
            <p class="mut" style="margin:0 0 20px">Comment s'est passée cette activité ?</p>

            <form method="POST" action="{{ route('public.feedback.store', ['attendance' => $attendance->reference]) }}">
                @csrf
                <div class="field {{ $errors->has('rating') ? 'invalid' : '' }}">
                    <div class="ratepick" role="radiogroup" aria-label="Note">
                        @foreach ([1, 2, 3, 4, 5] as $n)
                            <label class="rateopt">
                                <input type="radio" name="rating" value="{{ $n }}" @checked(old('rating') == $n) required>
                                <span>{{ $n }}</span>
                            </label>
                        @endforeach
                    </div>
                    <div class="err-msg">{{ $errors->first('rating') }}</div>
                </div>

                <div class="field" style="margin-top:16px">
                    <label for="comment">Commentaire <span class="opt">(facultatif)</span></label>
                    <textarea class="control" id="comment" name="comment" rows="4" maxlength="2000" placeholder="Ce qui vous a plu, ce qui pourrait être amélioré…">{{ old('comment') }}</textarea>
                </div>

                <button type="submit" class="btn btn--primary btn--block btn--lg" style="margin-top:16px">Envoyer mon avis</button>
            </form>
        @endif
    </section>
@endsection
