<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Projection QR — {{ $event->title }}</title>
    <link rel="stylesheet" href="{{ versioned_asset('css/tokens.css') }}">
    @if ($branding->accentColor)
        <style>
            :root{
                --accent: {{ $branding->accentColor }};
                --accent-soft: color-mix(in srgb, {{ $branding->accentColor }} 12%, #fff);
            }
        </style>
    @endif
    <style>
        body{min-height:100vh;display:grid;place-items:center;padding:24px}
        /* Fond flouté : personnes de la photo non reconnaissables (droit à l'image) */
        body.has-backdrop{background:var(--bg);isolation:isolate}
        body.has-backdrop::before{content:"";position:fixed;inset:0;z-index:-1;background:var(--backdrop) center/cover no-repeat;filter:blur(6px) saturate(95%);transform:scale(1.12)}
        body.has-backdrop::after{content:"";position:fixed;inset:0;z-index:-1;background:rgba(13,15,21,.18)}
        body.theme-registre h1{font-family:"Iowan Old Style","Charter","Georgia","Noto Serif",serif;font-weight:700;letter-spacing:0}
/* Institutionnel : angles droits obligatoires sur tous les éléments */
        body.theme-registre .sheet,body.theme-registre .proj,body.theme-registre .qr-frame,body.theme-registre .qrbox,
        body.theme-registre .cta,body.theme-registre .btn,body.theme-registre .inst-hd,body.theme-registre .inst-hd__flag{border-radius:0}
        body.has-backdrop .proj{background:color-mix(in srgb,var(--surface) 92%,transparent);border-radius:24px;padding:28px 32px;box-shadow:var(--sh-3)}
        .proj{text-align:center;max-width:520px}
        .proj__logo{height:44px;width:auto;margin:0 auto 16px;display:block;object-fit:contain}
        .proj h1{font-size:1.6rem;font-weight:800;margin:0 0 4px}
        .proj .meta{color:var(--muted);margin-bottom:22px}
        .qrbox{background:var(--surface);border:1px solid var(--border);border-radius:24px;box-shadow:var(--sh-3);padding:26px;display:inline-block}
        .qrbox img{width:min(64vw,340px);height:auto;display:block}
        .count{margin-top:18px;font-weight:700;color:var(--muted)}
        .count b{color:var(--brand-orange);font-variant-numeric:tabular-nums}
        .hint{margin-top:10px;font-size:.85rem;color:var(--faint)}
    </style>
</head>
<body class="{{ $branding->backgroundUrl ? 'has-backdrop' : '' }}{{ $branding->isInstitutional ? ' theme-registre' : '' }}" @if ($branding->backgroundUrl) style="--backdrop:url('{{ $branding->backgroundUrl }}')" @endif>
    {{-- NOTE : habillage minimal fonctionnel ; l'écran de projection fidèle
         (dashboard.html) sera intégré ultérieurement. --}}
    <div class="proj">
        @if ($branding->isInstitutional)
            @include('partials.institutional-header', ['stacked' => true])
        @else
            <img class="proj__logo" src="{{ $branding->logoUrl }}" alt="{{ $branding->orgName }}">
        @endif
        <h1>{{ $event->title }}</h1>
        <div class="meta">
            @if ($branding->isInstitutional)
                {{ ucfirst($event->starts_at->translatedFormat('l j F Y')) }}, de {{ $event->starts_at->format('H\\hi') }} à {{ $event->ends_at->format('H\\hi') }}@if($event->location)<br>{{ $event->location }}@endif
            @else
                {{ $event->starts_at->translatedFormat('j M Y · H:i') }}@if($event->location) · {{ $event->location }}@endif
            @endif
        </div>
        <div class="qrbox"><img id="qr" alt="QR d'émargement"></div>
        <div class="count" id="count"></div>
        <div class="hint">Scannez ce QR avec l'appareil photo de votre téléphone pour émarger.</div>
    </div>

    <script>
        var CURRENT = @json(route('admin.events.qr.current', $event));
        var img = document.getElementById('qr');
        var count = document.getElementById('count');
        var remaining = 0;

        function refresh() {
            fetch(CURRENT, { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    img.src = d.svg;
                    if (d.mode === 'tournant') { remaining = d.expires_in; render(); }
                    else { count.textContent = ''; }
                })
                .catch(function () {});
        }
        function render() {
            if (remaining > 0) { count.innerHTML = 'Nouveau QR dans <b>' + remaining + ' s</b>'; }
        }
        // Polling : image toutes les 15 s (rotation), décompte chaque seconde.
        setInterval(function () { remaining -= 1; if (remaining <= 0) refresh(); else render(); }, 1000);
        refresh();
    </script>
</body>
</html>
