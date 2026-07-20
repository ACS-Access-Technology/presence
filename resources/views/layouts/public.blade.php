<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Émargement — ACS Groupe · Presence</title>
    <link rel="stylesheet" href="{{ asset('css/tokens.css') }}">
    <link rel="stylesheet" href="{{ asset('css/participant.css') }}">
    @stack('head')
</head>
<body>
    <div class="phone">
        <header class="appbar">
            <img src="{{ asset('assets/logo-acs-groupe.png') }}" alt="ACS Groupe">
            <div class="appbar__ev">
                <span class="appbar__title">{{ $event->title }}</span>
                <span class="appbar__meta">
                    {{ $event->starts_at->translatedFormat('j M Y · H:i') }}@if($event->location) · {{ $event->location }}@endif
                </span>
            </div>
        </header>

        @yield('content')
    </div>

    @stack('scripts')
</body>
</html>
