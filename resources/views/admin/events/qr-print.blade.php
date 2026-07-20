<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>QR à imprimer — {{ $event->title }}</title>
    <link rel="stylesheet" href="{{ asset('css/tokens.css') }}">
    <style>
        html,body{height:100%}
        body{
            display:grid;place-items:center;padding:40px 20px;
            background:#0d0f15;position:relative;overflow-x:hidden;
        }
        .bg{
            position:fixed;inset:-20px;z-index:0;
            background:url('{{ asset('images/qr-print-bg.jpg') }}') center/cover no-repeat;
            filter:blur(22px) saturate(95%);
            transform:scale(1.08);
        }
        .bg::after{
            content:"";position:absolute;inset:0;
            background:linear-gradient(160deg,rgba(14,17,35,.82),rgba(30,42,120,.68) 55%,rgba(240,122,19,.35));
        }

        .sheet{
            position:relative;z-index:1;
            width:100%;max-width:420px;
            background:var(--surface);
            border-radius:24px;
            box-shadow:var(--sh-3);
            padding:36px 32px 30px;
            text-align:center;
        }
        .sheet__brand{
            display:flex;align-items:center;justify-content:center;gap:8px;
            font-size:.72rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;
            color:var(--brand-orange);margin-bottom:18px;
        }
        .sheet__brand::before,.sheet__brand::after{content:"";height:1px;width:24px;background:var(--border-strong)}

        .sheet h1{font-size:1.35rem;font-weight:750;margin:0 0 8px;color:var(--text);line-height:1.25}
        .sheet .meta{
            display:flex;flex-direction:column;gap:4px;
            color:var(--muted);font-size:.88rem;margin-bottom:22px;
        }
        .sheet .meta span{display:inline-flex;align-items:center;gap:6px;justify-content:center}
        .sheet .meta svg{width:15px;height:15px;flex:0 0 auto;color:var(--accent)}

        .qr-frame{
            display:inline-flex;padding:16px;background:#fff;
            border:1px solid var(--border);border-radius:18px;
            box-shadow:var(--sh-1);margin-bottom:20px;
        }
        .qr-frame img{width:260px;height:260px;display:block}

        .cta{
            display:inline-flex;align-items:center;gap:8px;
            font-weight:700;font-size:.95rem;color:var(--accent);
            background:var(--accent-soft);border-radius:var(--r-pill);
            padding:9px 18px;margin-bottom:6px;
        }
        .cta svg{width:16px;height:16px}

        .altlink{
            font-size:.78rem;color:var(--muted);margin-top:10px;
            padding-top:14px;border-top:1px dashed var(--border);
        }
        .altlink a{
            display:block;color:var(--accent);font-weight:650;
            word-break:break-all;margin-top:4px;text-decoration:none;
        }
        .altlink a:hover{text-decoration:underline}

        button.btn{margin-top:22px;width:100%}

        @media print{
            body{background:#fff;padding:0}
            .bg{display:none}
            .sheet{box-shadow:none;padding:0;max-width:100%}
            .noprint{display:none}
        }
    </style>
</head>
<body>
    <div class="bg noprint" aria-hidden="true"></div>

    <div class="sheet">
        <div class="sheet__brand">ACS Groupe</div>
        <h1>{{ $event->title }}</h1>
        <div class="meta">
            <span>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="3"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                {{ $event->starts_at->translatedFormat('j M Y · H:i') }}
            </span>
            @if($event->location)
            <span>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s7-6.2 7-12a7 7 0 1 0-14 0c0 5.8 7 12 7 12Z"/><circle cx="12" cy="10" r="2.5"/></svg>
                {{ $event->location }}
            </span>
            @endif
        </div>

        <div class="qr-frame">
            <img src="{{ $svg }}" alt="QR d'émargement">
        </div>

        <div class="cta">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><path d="M14 14h3v3h-3zM19 14h2v2h-2zM14 19h2v2h-2zM19 19h2v2h-2z"/></svg>
            Scannez pour émarger
        </div>

        <div class="altlink">
            Pas de lecteur QR ? Ouvrez ce lien
            <a href="{{ $url }}">{{ $url }}</a>
        </div>

        <button class="btn btn--primary noprint" onclick="window.print()">Imprimer</button>
    </div>
</body>
</html>
