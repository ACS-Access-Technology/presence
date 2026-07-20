<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Connexion · Presence · ACS Groupe</title>
    <link rel="stylesheet" href="{{ asset('css/tokens.css') }}">
    <link rel="stylesheet" href="{{ asset('css/auth.css') }}">
</head>
<body>

<button class="theme-tgl" type="button" onclick="toggleTheme()" aria-label="Basculer le thème">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 12.8A9 9 0 1 1 11.2 3 7 7 0 0 0 21 12.8Z"/></svg>
</button>

<div class="wrap">
    <aside class="brand" aria-hidden="true">
        <div class="brand__logo">
            <img src="{{ asset('assets/logo-acs-groupe.png') }}" alt="">
            <span class="pn">Presence<span>.</span></span>
        </div>
        <div class="brand__mid">
            <h2>L'émargement numérique d'ACS Groupe.</h2>
            <p>Créez vos événements, projetez un QR code et suivez les présences en temps réel — sans papier.</p>
            <div class="brand__feat">
                <div><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6 9 17l-5-5"/></svg>QR statique imprimable ou tournant projeté</div>
                <div><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6 9 17l-5-5"/></svg>Géolocalisation et signature à la validation</div>
                <div><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6 9 17l-5-5"/></svg>Statistiques et export CSV en un clic</div>
            </div>
        </div>
        <div class="brand__foot">© {{ date('Y') }} ACS Groupe · Abidjan, Côte d'Ivoire</div>
    </aside>

    <main class="formside">
        <div class="card">
            <div class="">
                <img src="{{ asset('assets/logo-acs-groupe.png') }}" alt="ACS Groupe" width="75%" height="75%">
                <!-- <span class="pn">Presence<span>.</span></span> -->
            </div>
            <h1>Connexion</h1>
            <p class="lead">Accès réservé aux comptes internes ACS Groupe.</p>

            <div class="alert {{ $errors->any() ? 'show' : '' }}" role="alert">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/></svg>
                <span>{{ $errors->first('email') ?: 'Vérifiez vos identifiants.' }}</span>
            </div>

            <form method="POST" action="{{ route('login') }}" novalidate>
                @csrf
                <div class="field {{ $errors->has('email') ? 'invalid' : '' }}">
                    <label for="email">Adresse email professionnelle</label>
                    <div class="inputwrap">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>
                        <input class="control" id="email" name="email" type="email" inputmode="email" autocomplete="username"
                               placeholder="prenom.nom@acs.ci" value="{{ old('email') }}"
                               @if($errors->has('email')) aria-invalid="true" @endif required autofocus>
                    </div>
                    <div class="err-msg">Entrez une adresse email valide.</div>
                </div>

                <div class="field">
                    <label for="pw">Mot de passe</label>
                    <div class="inputwrap">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>
                        <input class="control" id="pw" name="password" type="password" autocomplete="current-password" placeholder="Votre mot de passe" required>
                        <button type="button" class="pw-toggle" onclick="togglePw()" aria-label="Afficher le mot de passe" id="pwbtn">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                    </div>
                </div>

                <div class="row-between">
                    <label class="remember"><input type="checkbox" name="remember" value="1"> Rester connecté</label>
                    <button type="button" class="linkbtn" onclick="alert('Réinitialisation du mot de passe : contactez un administrateur ACS Groupe (pas de self-service au MVP).')">Mot de passe oublié ?</button>
                </div>

                <button type="submit" class="btn">Se connecter</button>
            </form>

            <div class="note">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>
                <span>Pas d'inscription publique. Les comptes sont créés par un administrateur ACS Groupe.</span>
            </div>
            <div class="foot">Presence · ACS Groupe</div>
        </div>
    </main>
</div>

<script>
    function togglePw() {
        var i = document.getElementById('pw'), show = i.type === 'password';
        i.type = show ? 'text' : 'password';
        document.getElementById('pwbtn').innerHTML = show
            ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17.9 17.9A10.4 10.4 0 0 1 12 20c-7 0-11-8-11-8a19 19 0 0 1 5.1-5.9M9.9 4.2A10.6 10.6 0 0 1 12 4c7 0 11 8 11 8a19 19 0 0 1-2.2 3.2M1 1l22 22M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>'
            : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"/><circle cx="12" cy="12" r="3"/></svg>';
    }
    function toggleTheme() {
        var cur = document.documentElement.getAttribute('data-theme');
        var dark = cur ? cur === 'dark' : matchMedia('(prefers-color-scheme:dark)').matches;
        document.documentElement.setAttribute('data-theme', dark ? 'light' : 'dark');
    }
</script>
</body>
</html>
