<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Recrutement') — {{ $branding->orgName }} · Presence</title>
    <meta name="theme-color" content="{{ $branding->accentColorOrDefault() }}">
    <link rel="stylesheet" href="{{ versioned_asset('css/tokens.css') }}">
    <link rel="stylesheet" href="{{ versioned_asset('css/recruitment.css') }}">
    @if ($branding->accentColor)
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
<body @yield('body-attributes', '')>
    <header class="rec-appbar">
        <img src="{{ $branding->logoUrl }}" alt="{{ $branding->orgName }}">
        <div>
            <div class="rec-appbar__org">{{ $branding->orgName }}</div>
            <div class="rec-appbar__sub">Test de recrutement</div>
        </div>
    </header>

    @yield('content')

    @stack('scripts')
</body>
</html>
