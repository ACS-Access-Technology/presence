<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Tableau de bord') · Presence · ACS Groupe</title>
    <script>(function(){var t=localStorage.getItem('presence-theme');if(t)document.documentElement.setAttribute('data-theme',t);})();</script>
    <link rel="stylesheet" href="{{ versioned_asset('css/tokens.css') }}">
    <link rel="stylesheet" href="{{ versioned_asset('css/dashboard.css') }}">
    @stack('head')
</head>
<body class="{{ request()->boolean('overlay') ? 'overlay-page' : '' }}">
@php($user = auth()->user())
<div class="app">
    <aside class="side" id="side" aria-label="Navigation principale">
        <div class="side__brand">
            <img src="{{ asset('assets/logo-acs-groupe.png') }}" alt="ACS Groupe" width="100%">
        </div>
        <nav class="nav" aria-label="Sections">
            <a href="{{ route('admin.events.index') }}" class="{{ ($nav ?? '') === 'events' ? 'active' : '' }}" @if(($nav ?? '')==='events') aria-current="page" @endif>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                Événements
            </a>
            <a href="{{ route('admin.participants.index') }}" class="{{ ($nav ?? '') === 'participants' ? 'active' : '' }}" @if(($nav ?? '')==='participants') aria-current="page" @endif>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                Participants
            </a>
            <a href="{{ route('admin.statistics') }}" class="{{ ($nav ?? '') === 'statistics' ? 'active' : '' }}" @if(($nav ?? '')==='statistics') aria-current="page" @endif>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 3v18h18"/><path d="M18 17V9M13 17V5M8 17v-3"/></svg>
                Statistiques
            </a>
            <a href="{{ route('admin.portfolio') }}" class="{{ ($nav ?? '') === 'portfolio' ? 'active' : '' }}" @if(($nav ?? '')==='portfolio') aria-current="page" @endif>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.8"/><path d="M21 15l-5-5L5 21"/></svg>
                Portfolio
            </a>
            @if($user->isSuperAdmin())
                <div class="navlbl">Holding</div>
                <a href="{{ route('admin.filiales.index') }}" class="{{ ($nav ?? '') === 'filiales' ? 'active' : '' }}" @if(($nav ?? '')==='filiales') aria-current="page" @endif>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 21h18M5 21V7l7-4 7 4v14M9 9h.01M15 9h.01M9 13h.01M15 13h.01M9 17h6"/></svg>
                    Filiales
                    <span class="navsuper">SUPER</span>
                </a>
            @endif
            @if($user->canManageSettings())
                @unless($user->isSuperAdmin())<div class="navlbl">Administration</div>@endunless
                <a href="{{ route('admin.settings.index') }}" class="{{ ($nav ?? '') === 'settings' ? 'active' : '' }}" @if(($nav ?? '')==='settings') aria-current="page" @endif>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                    Paramètres
                </a>
            @endif
            <form method="POST" action="{{ route('logout') }}" style="margin-top:6px">
                @csrf
                <button type="submit" class="nav-logout">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/></svg>
                    Se déconnecter
                </button>
            </form>
        </nav>
        <div class="side__foot">
            <a class="user" href="{{ route('admin.profile.edit') }}" style="text-decoration:none;color:inherit">
                <div class="av">{{ \Illuminate\Support\Str::of($user->name)->explode(' ')->map(fn($w)=>mb_substr($w,0,1))->take(2)->implode('') }}</div>
                <div>
                    <div class="user__n">{{ $user->name }}</div>
                    <div class="user__r">{{ $user->role->label() }}</div>
                </div>
            </a>
        </div>
    </aside>

    <div class="main">
        <header class="topbar">
            <button class="iconbtn menutgl" onclick="document.getElementById('side').classList.toggle('open')" aria-label="Ouvrir le menu">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M3 6h18M3 12h18M3 18h18"/></svg>
            </button>
            @hasSection('crumbs')
                <nav class="crumbs" aria-label="Fil d'Ariane">@yield('crumbs')</nav>
            @else
                <span class="pagetitle">@yield('title', 'Tableau de bord')</span>
            @endif
            <span class="sp"></span>
            @include('admin.partials.filiale-context')
            @yield('topbar-actions')
            <button class="iconbtn" type="button" onclick="toggleTheme()" aria-label="Basculer le thème">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 12.8A9 9 0 1 1 11.2 3 7 7 0 0 0 21 12.8Z"/></svg>
            </button>
        </header>

        <main class="content">
            @if (session(\App\Http\Middleware\ApplyFilialeScope::CONTEXT_WARNING_KEY))
                <div class="flash-warn" role="alert" style="display:flex;gap:9px;align-items:flex-start;margin-bottom:16px;padding:12px 14px;border-radius:12px;background:color-mix(in srgb, var(--warn, #e0620d) 12%, transparent);color:var(--warn, #e0620d);border:1px solid color-mix(in srgb, var(--warn, #e0620d) 32%, transparent)">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.9" style="flex-shrink:0;margin-top:1px"><path d="M12 9v4M12 17h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/></svg>
                    <span>{{ session(\App\Http\Middleware\ApplyFilialeScope::CONTEXT_WARNING_KEY) }}</span>
                </div>
            @endif
            @yield('content')
        </main>
    </div>
</div>

<div class="toast" id="toast" role="status" aria-live="polite"></div>
@unless(request()->boolean('overlay'))
<div class="create-sheet" id="create-sheet" hidden>
    <button class="create-sheet__backdrop" type="button" aria-label="Fermer la création"></button>
    <section class="create-sheet__panel" role="dialog" aria-modal="true" aria-labelledby="create-sheet-title">
        <header class="create-sheet__head">
            <span class="create-sheet__handle" aria-hidden="true"></span>
            <div>
                <h2 id="create-sheet-title">Nouvel événement</h2>
                <p>Créez et configurez votre événement</p>
            </div>
            <button class="create-sheet__close" type="button" aria-label="Fermer la création">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M6 6l12 12M18 6 6 18"/></svg>
            </button>
        </header>
        <iframe class="create-sheet__frame" id="create-sheet-frame" title="Formulaire de création d'un événement"></iframe>
    </section>
</div>
@endunless
<script>
    function toggleTheme() {
        var cur = document.documentElement.getAttribute('data-theme');
        var dark = cur ? cur === 'dark' : matchMedia('(prefers-color-scheme:dark)').matches;
        var next = dark ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', next);
        localStorage.setItem('presence-theme', next);
    }
    window.ADMIN_SHELL = {
        createUrl: @json(route('admin.events.create')),
        overlay: @json(request()->boolean('overlay')),
    };
</script>
<script src="{{ versioned_asset('js/admin-shell.js') }}"></script>
<script>
    // Purge d'un bug antérieur : le service worker de la page publique
    // d'émargement s'enregistrait sans `scope`, prenant par défaut le contrôle
    // de tout le site (racine) au lieu de /e/ seulement. Corrigé à la source,
    // mais les navigateurs ayant déjà visité /e/{slug} gardent l'ancien
    // enregistrement racine tant qu'il n'est pas explicitement désinscrit ici.
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.getRegistrations().then(function (regs) {
            regs.forEach(function (reg) {
                if (reg.scope === location.origin + '/') { reg.unregister(); }
            });
        });
    }
</script>
@stack('scripts')
</body>
</html>
