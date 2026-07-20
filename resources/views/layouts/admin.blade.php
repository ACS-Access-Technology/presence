<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Tableau de bord') · Presence · ACS Groupe</title>
    <link rel="stylesheet" href="{{ asset('css/tokens.css') }}">
    <link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
    @stack('head')
</head>
<body>
@php($user = auth()->user())
<div class="app">
    <aside class="side" id="side" aria-label="Navigation principale">
        <div class="side__logo"><img src="{{ asset('assets/logo-acs-groupe.png') }}" alt="ACS Groupe"></div>
        <nav class="nav" aria-label="Sections">
            <a href="{{ route('admin.events.index') }}" class="{{ ($nav ?? '') === 'events' ? 'active' : '' }}" @if(($nav ?? '')==='events') aria-current="page" @endif>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                Événements
            </a>
            <a href="{{ route('admin.events.create') }}" class="{{ ($nav ?? '') === 'events-create' ? 'active' : '' }}" @if(($nav ?? '')==='events-create') aria-current="page" @endif>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 5v14M5 12h14"/></svg>
                Nouvel événement
            </a>
            <a href="{{ route('admin.participants.index') }}" class="{{ ($nav ?? '') === 'participants' ? 'active' : '' }}" @if(($nav ?? '')==='participants') aria-current="page" @endif>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                Participants
            </a>
            <a href="{{ route('admin.portfolio') }}" class="{{ ($nav ?? '') === 'portfolio' ? 'active' : '' }}" @if(($nav ?? '')==='portfolio') aria-current="page" @endif>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.8"/><path d="M21 15l-5-5L5 21"/></svg>
                Portfolio
            </a>
            @if($user->isAdmin())
                <div class="navlbl">Administration</div>
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
            <div class="user">
                <div class="av">{{ \Illuminate\Support\Str::of($user->name)->explode(' ')->map(fn($w)=>mb_substr($w,0,1))->take(2)->implode('') }}</div>
                <div>
                    <div class="user__n">{{ $user->name }}</div>
                    <div class="user__r">{{ $user->role->label() }} · ACS</div>
                </div>
            </div>
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
            @yield('topbar-actions')
        </header>

        <main class="content">
            @yield('content')
        </main>
    </div>
</div>

<div class="toast" id="toast" role="status" aria-live="polite"></div>
@stack('scripts')
</body>
</html>
