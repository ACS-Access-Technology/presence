<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>QR à imprimer — {{ $event->title }}</title>
    <link rel="stylesheet" href="{{ asset('css/tokens.css') }}">
    <style>
        body{padding:40px;display:grid;place-items:center;background:#fff;color:#12141a}
        .sheet{text-align:center;max-width:520px}
        .sheet img{width:340px;height:auto;margin:18px 0}
        .sheet h1{font-size:1.6rem;margin:0 0 6px}
        .sheet .meta{color:#565d6b;margin-bottom:8px}
        .sheet .cta{font-weight:700;margin-top:8px}
        @media print{.noprint{display:none}}
    </style>
</head>
<body>
    <div class="sheet">
        <h1>{{ $event->title }}</h1>
        <div class="meta">{{ $event->starts_at->translatedFormat('j M Y · H:i') }}@if($event->location) · {{ $event->location }}@endif</div>
        <img src="{{ $svg }}" alt="QR d'émargement">
        <div class="cta">Scannez pour émarger</div>
        <button class="btn btn--primary noprint" style="margin-top:20px" onclick="window.print()">Imprimer</button>
    </div>
</body>
</html>
