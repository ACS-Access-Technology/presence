<!doctype html>
<html lang="fr">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0;padding:0;background:#eef0f4;font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#12141a">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef0f4;padding:24px 12px">
        <tr><td align="center">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e3e6ec">
                <tr><td style="background:{{ $accentColor }};padding:22px 28px;color:#fff;font-weight:800;font-size:1.1rem;letter-spacing:-.02em">
                    {{ $orgName }}
                </td></tr>
                <tr><td style="padding:28px">
                    <h1 style="font-size:1.25rem;margin:0 0 6px">Liste d'émargement</h1>
                    <p style="color:#565d6b;margin:0 0 18px">Bonjour{{ $recipientName ? ' '.$recipientName : '' }},</p>
                    <p style="margin:0 0 16px;line-height:1.6">
                        Veuillez trouver ci-joint la liste d'émargement de <strong>{{ $eventTitle }}</strong> ({{ $eventDate }}@if($location) · {{ $location }}@endif) :
                        {{ $count }} participant{{ $count > 1 ? 's' : '' }}.
                    </p>
                </td></tr>
                <tr><td style="padding:16px 28px;border-top:1px solid #e3e6ec;color:#8a91a0;font-size:.78rem">
                    Ce document contient des données personnelles : merci de ne pas le diffuser au-delà des personnes concernées.
                </td></tr>
            </table>
        </td></tr>
    </table>
</body>
</html>
