<!doctype html>
<html lang="fr">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0;padding:0;background:#eef0f4;font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#12141a">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef0f4;padding:24px 12px">
        <tr><td align="center">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e3e6ec">
                <tr><td style="background:#1E2A78;padding:22px 28px;color:#fff;font-weight:800;font-size:1.1rem;letter-spacing:-.02em">
                    {{ $orgName }} · Presence
                </td></tr>
                <tr><td style="padding:28px">
                    <h1 style="font-size:1.25rem;margin:0 0 6px">Présence confirmée</h1>
                    <p style="color:#565d6b;margin:0 0 18px">Bonjour {{ $firstName }},</p>
                    <p style="margin:0 0 16px;line-height:1.6">
                        Votre présence à <strong>{{ $eventTitle }}</strong> ({{ $eventDate }}@if($location) · {{ $location }}@endif) a bien été enregistrée. Merci de votre participation.
                    </p>
                    <p style="display:inline-block;background:#f7f8fa;border:1px solid #e3e6ec;border-radius:10px;padding:9px 14px;font-size:.9rem;margin:0">
                        Référence : <strong>{{ $reference }}</strong>
                    </p>
                </td></tr>
                <tr><td style="padding:16px 28px;border-top:1px solid #e3e6ec;color:#8a91a0;font-size:.78rem">
                    Cet email de confirmation est envoyé automatiquement par {{ $orgName }}. Merci de ne pas y répondre.
                </td></tr>
            </table>
        </td></tr>
    </table>
</body>
</html>
