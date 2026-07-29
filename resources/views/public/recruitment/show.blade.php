<!doctype html>
<html lang="fr">
<head><meta charset="utf-8"><title>{{ $test->titre }}</title></head>
<body>
<h1>{{ $test->titre }}</h1>
<p>{{ $test->instructions }}</p>
<form method="POST" action="{{ route('public.recruitment.store', $test->slug) }}">
    @csrf
    @if ($errors->any())
        <p>{{ $errors->first() }}</p>
    @endif
    <input type="text" name="nom" value="{{ old('nom') }}" placeholder="Nom" required>
    <input type="text" name="prenom" value="{{ old('prenom') }}" placeholder="Prénom" required>
    <input type="email" name="email" value="{{ old('email') }}" placeholder="Email" required>
    <input type="tel" name="telephone" value="{{ old('telephone') }}" placeholder="Téléphone (optionnel)">
    <button type="submit">Commencer le test</button>
</form>
</body>
</html>
