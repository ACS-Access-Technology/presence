<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <style>
        body{font-family:sans-serif;font-size:11px;color:#12141a}
        h1{font-size:16px;margin:0 0 2px;color:#1E2A78}
        .meta{color:#565d6b;font-size:10px;margin:0 0 14px}
        table{width:100%;border-collapse:collapse}
        th,td{padding:6px 8px;border-bottom:1px solid #e3e6ec;text-align:left}
        th{background:#f7f8fa;font-size:9px;text-transform:uppercase;letter-spacing:.04em;color:#565d6b}
        tfoot td{font-weight:700;border-top:2px solid #1E2A78;border-bottom:none}
    </style>
</head>
<body>
    <h1>{{ $event->title }}</h1>
    <p class="meta">
        {{ $event->starts_at->translatedFormat('j F Y') }} · {{ $event->starts_at->format('H:i') }} – {{ $event->ends_at->format('H:i') }}
        @if($event->location) · {{ $event->location }} @endif
        · Exporté le {{ now()->translatedFormat('j F Y à H:i') }}
    </p>
    <table>
        <thead>
            <tr>
                <th>Nom</th><th>Prénom</th><th>Email</th><th>Téléphone</th>
                <th>Entité/Entreprise</th><th>Direction</th><th>Service</th><th>Poste</th>
                <th>Arrivée</th><th>Départ</th><th>Saisie</th><th>Statut</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $r)
                <tr>
                    <td>{{ $r['last_name'] }}</td>
                    <td>{{ $r['first_name'] }}</td>
                    <td>{{ $r['email'] }}</td>
                    <td>{{ $r['phone'] }}</td>
                    <td>{{ $r['company'] }}</td>
                    <td>{{ $r['direction'] }}</td>
                    <td>{{ $r['service'] }}</td>
                    <td>{{ $r['position'] }}</td>
                    <td>{{ $r['time'] }}</td>
                    <td>{{ $r['left'] ?? '' }}</td>
                    <td>{{ $r['manual'] ? 'Manuelle' : 'QR' }}</td>
                    <td>{{ $r['recurrent'] ? 'Récurrent' : 'Nouveau' }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr><td colspan="8"></td><td colspan="4">{{ count($rows) }} présent{{ count($rows) > 1 ? 's' : '' }}</td></tr>
        </tfoot>
    </table>
</body>
</html>
