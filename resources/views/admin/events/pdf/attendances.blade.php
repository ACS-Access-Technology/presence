<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <style>
        body{font-family:sans-serif;font-size:10px;color:#12141a}
        h1{font-size:16px;margin:0 0 2px;color:#1E2A78}
        .meta{color:#565d6b;font-size:10px;margin:0 0 14px}
        table{width:100%;border-collapse:collapse;table-layout:fixed}
        th,td{padding:5px 6px;border-bottom:1px solid #e3e6ec;text-align:left;overflow:hidden;word-wrap:break-word}
        th{background:#f7f8fa;font-size:8px;text-transform:uppercase;letter-spacing:.03em;color:#565d6b}
        thead{display:table-header-group}
        tr{page-break-inside:avoid}
        tfoot td{font-weight:700;border-top:2px solid #1E2A78;border-bottom:none}
        .sig{height:34px;max-width:70px;display:block}
        .col-name{width:10%}.col-first{width:9%}.col-email{width:15%}.col-phone{width:9%}
        .col-company{width:11%}.col-dir{width:9%}.col-pos{width:9%}
        .col-time{width:6%}.col-status{width:7%}.col-sig{width:9%}
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
                <th class="col-name">Nom</th><th class="col-first">Prénom</th><th class="col-email">Email</th>
                <th class="col-phone">Téléphone</th><th class="col-company">Entité/Entreprise</th>
                <th class="col-dir">Direction</th><th class="col-pos">Poste</th>
                <th class="col-time">Arrivée</th><th class="col-time">Départ</th>
                <th class="col-status">Saisie</th><th class="col-status">Statut</th>
                <th class="col-sig">Signature</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $r)
                <tr>
                    <td class="col-name">{{ $r['last_name'] }}</td>
                    <td class="col-first">{{ $r['first_name'] }}</td>
                    <td class="col-email">{{ $r['email'] }}</td>
                    <td class="col-phone">{{ $r['phone'] }}</td>
                    <td class="col-company">{{ $r['company'] }}</td>
                    <td class="col-dir">{{ $r['direction'] }}</td>
                    <td class="col-pos">{{ $r['position'] }}</td>
                    <td class="col-time">{{ $r['time'] }}</td>
                    <td class="col-time">{{ $r['left'] ?? '—' }}</td>
                    <td class="col-status">{{ $r['manual'] ? 'Manuelle' : 'QR' }}</td>
                    <td class="col-status">{{ $r['recurrent'] ? 'Récurrent' : 'Nouveau' }}</td>
                    <td class="col-sig">
                        @if(!empty($r['signature_data_uri']))
                            <img class="sig" src="{{ $r['signature_data_uri'] }}" alt="">
                        @else
                            —
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr><td colspan="9"></td><td colspan="3">{{ count($rows) }} présent{{ count($rows) > 1 ? 's' : '' }}</td></tr>
        </tfoot>
    </table>
</body>
</html>
