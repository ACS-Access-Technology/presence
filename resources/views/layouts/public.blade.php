<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Émargement — {{ $branding->orgName }}{{ $branding->onBehalfOf ? '' : ' · Presence' }}</title>
    <meta name="theme-color" content="{{ $branding->accentColorOrDefault() }}">
    <link rel="stylesheet" href="{{ versioned_asset('css/tokens.css') }}">
    <link rel="stylesheet" href="{{ versioned_asset('css/participant.css') }}">
    @if ($branding->accentColor)
        {{-- Accent de la filiale : on dérive la famille de tokens depuis l'unique
             couleur configurée (validée `#rrggbb`) via color-mix, pour garder une
             palette cohérente en clair comme en sombre sans stocker 4 nuances. --}}
        <style>
            :root {
                --accent: {{ $branding->accentColor }};
                --accent-hover: color-mix(in srgb, {{ $branding->accentColor }} 82%, #000);
                --accent-bright: color-mix(in srgb, {{ $branding->accentColor }} 68%, #fff);
                --accent-soft: color-mix(in srgb, {{ $branding->accentColor }} 12%, #fff);
                --on-accent: #fff;
            }
            @media (prefers-color-scheme: dark) {
                :root:not([data-theme="light"]) {
                    --accent: color-mix(in srgb, {{ $branding->accentColor }} 62%, #fff);
                    --accent-soft: color-mix(in srgb, {{ $branding->accentColor }} 24%, #10131c);
                }
            }
        </style>
    @endif
    @stack('head')
</head>
<body>
    <div class="phone">
        @include('partials.institutional-header')
        <header class="appbar">
            @unless ($branding->isInstitutional)
                <img src="{{ $branding->logoUrl }}" alt="{{ $branding->orgName }}">
            @endunless
            <div class="appbar__ev">
                <span class="appbar__title">{{ $event->title }}</span>
                <span class="appbar__meta">
                    {{ ucfirst($event->starts_at->translatedFormat('l j F Y')) }}, de {{ $event->starts_at->format('H\\hi') }} à {{ $event->ends_at->format('H\\hi') }}
                </span>
                @if ($event->location)
                    <span class="appbar__place">{{ $event->location }}</span>
                @endif
            </div>
        </header>

        @yield('content')
    </div>

    @stack('scripts')
</body>
</html>
