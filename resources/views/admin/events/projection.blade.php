<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Projection QR — {{ $event->title }}</title>
    <link rel="stylesheet" href="{{ versioned_asset('css/tokens.css') }}">
    <style>
        body{min-height:100vh;display:grid;place-items:center;padding:24px}
        .proj{text-align:center;max-width:520px}
        .proj h1{font-size:1.6rem;font-weight:800;margin:0 0 4px}
        .proj .meta{color:var(--muted);margin-bottom:22px}
        .qrbox{background:var(--surface);border:1px solid var(--border);border-radius:24px;box-shadow:var(--sh-3);padding:26px;display:inline-block}
        .qrbox img{width:min(64vw,340px);height:auto;display:block}
        .count{margin-top:18px;font-weight:700;color:var(--muted)}
        .count b{color:var(--brand-orange);font-variant-numeric:tabular-nums}
        .hint{margin-top:10px;font-size:.85rem;color:var(--faint)}
    </style>
</head>
<body>
    {{-- NOTE : habillage minimal fonctionnel ; l'écran de projection fidèle
         (dashboard.html) sera intégré ultérieurement. --}}
    <div class="proj">
        <h1>{{ $event->title }}</h1>
        <div class="meta">{{ $event->starts_at->translatedFormat('j M Y · H:i') }}@if($event->location) · {{ $event->location }}@endif</div>
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
