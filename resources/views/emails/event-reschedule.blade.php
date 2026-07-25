<!doctype html>
<html lang="fr">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0;padding:0;background:#eef0f4;font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#12141a">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef0f4;padding:24px 12px">
        <tr><td align="center">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e3e6ec">
                <tr><td style="background:{{ $accentColor }};padding:22px 28px;color:#fff;font-weight:800;font-size:1.1rem;letter-spacing:-.02em">
                    {{ $orgName }} · Presence
                </td></tr>
                <tr><td style="padding:28px">
                    <h1 style="font-size:1.25rem;margin:0 0 6px">Événement reporté</h1>
                    <p style="color:#565d6b;margin:0 0 18px">Bonjour {{ $firstName }},</p>
                    <p style="margin:0 0 16px;line-height:1.6">
                        La date de <strong>{{ $eventTitle }}</strong>@if($location) · {{ $location }}@endif a changé.
                    </p>

                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 18px">
                        <tr><td style="padding:10px 14px;background:#f6f7fb;border-radius:10px 10px 0 0;color:#8a91a0;font-size:.85rem">
                            Ancien créneau · <span style="text-decoration:line-through">{{ $oldSchedule }}</span>
                        </td></tr>
                        <tr><td style="padding:12px 14px;background:#eef0fb;border-radius:0 0 10px 10px;color:#1E2A78;font-weight:700;font-size:.98rem">
                            Nouveau créneau · {{ $newSchedule }}
                        </td></tr>
                    </table>

                    @if($reason)
                        <p style="margin:0 0 16px;line-height:1.6;color:#565d6b;font-size:.92rem">Motif : {{ $reason }}</p>
                    @endif

                    <p style="margin:0 0 4px;line-height:1.6;color:#565d6b;font-size:.92rem">
                        Pour émarger, <strong>scannez le QR code présenté sur place</strong> le jour de l'événement, avec l'appareil photo de votre téléphone.
                    </p>

                    <p style="margin:18px 0 0;color:#8a91a0;font-size:.8rem">Un fichier .ics de mise à jour est joint : ajoutez-le à votre agenda, il corrige l'entrée existante.</p>
                </td></tr>
                <tr><td style="padding:16px 28px;border-top:1px solid #e3e6ec;color:#8a91a0;font-size:.78rem">
                    Cet email est envoyé automatiquement par {{ $orgName }}. Merci de ne pas y répondre.
                </td></tr>
            </table>
        </td></tr>
    </table>
</body>
</html>
