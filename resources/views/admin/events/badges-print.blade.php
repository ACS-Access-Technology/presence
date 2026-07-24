<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Badges — {{ $event->title }}</title>
    <link rel="stylesheet" href="{{ versioned_asset('css/tokens.css') }}">
    <style>
        body{background:var(--surface-2);padding:32px 20px 80px}
        .toolbar{max-width:900px;margin:0 auto 24px;display:flex;align-items:center;justify-content:space-between}
        .toolbar h1{font-size:1.1rem;margin:0}
        .grid{max-width:900px;margin:0 auto;display:grid;grid-template-columns:repeat(2, 1fr);gap:14px}
        .badge{
            background:#fff;border:1px dashed var(--border-strong);border-radius:14px;
            padding:22px 20px;break-inside:avoid;
        }
        .badge__brand{font-size:.68rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--brand-orange);margin-bottom:10px}
        .badge__name{font-size:1.15rem;font-weight:800;margin:0 0 4px;line-height:1.2}
        .badge__role{color:var(--muted);font-size:.86rem;margin:0 0 14px}
        .badge__ev{font-size:.78rem;color:var(--faint);border-top:1px solid var(--border);padding-top:10px}

        @media print{
            body{background:#fff;padding:0}
            .noprint{display:none}
            .grid{gap:0}
            .badge{border-radius:0;border:1px solid #000}
        }
    </style>
</head>
<body>
    <div class="toolbar noprint">
        <h1>{{ $event->title }} — {{ count($rows) }} badge{{ count($rows) > 1 ? 's' : '' }}</h1>
        <button class="btn btn--primary" onclick="window.print()">Imprimer</button>
    </div>

    @if (count($rows) === 0)
        <p style="text-align:center;color:var(--muted)">Aucun présent pour le moment.</p>
    @else
        <div class="grid">
            @foreach ($rows as $r)
                <div class="badge">
                    <div class="badge__brand">ACS Groupe</div>
                    <div class="badge__name">{{ $r['name'] }}</div>
                    <p class="badge__role">{{ $r['company'] ?: '—' }}@if($r['direction']) · {{ $r['direction'] }}@endif</p>
                    <div class="badge__ev">{{ $event->title }} · {{ $event->starts_at->translatedFormat('j M Y') }}</div>
                </div>
            @endforeach
        </div>
    @endif
</body>
</html>
