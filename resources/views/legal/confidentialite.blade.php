<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Politique de confidentialité — Presence · ACS Groupe</title>
    <link rel="stylesheet" href="{{ versioned_asset('css/tokens.css') }}">
    <style>
        body{max-width:760px;margin:0 auto;padding:32px 20px 80px;color:var(--text);background:var(--bg)}
        h1{font-size:1.6rem;font-weight:800;margin-bottom:4px}
        h2{font-size:1.1rem;font-weight:750;margin:32px 0 10px}
        p,li{font-size:.94rem;line-height:1.65;color:var(--muted)}
        ul{padding-left:20px;margin:8px 0}
        .draft{display:flex;gap:12px;align-items:flex-start;background:var(--warning-soft);color:var(--warning);border-radius:12px;padding:16px;margin:20px 0 28px;font-size:.9rem}
        .draft svg{width:20px;height:20px;flex:0 0 auto;margin-top:1px}
        .draft strong{color:var(--text)}
        .maj{font-size:.82rem;color:var(--faint)}
        a{color:var(--accent)}
    </style>
</head>
<body>
    <h1>Politique de confidentialité</h1>
    <p class="maj">Dernière mise à jour : {{ now()->translatedFormat('j F Y') }}</p>

    <!-- <div class="draft">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 9v4M12 17h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/></svg>
        <span><strong>Brouillon de travail — pas encore validé par un juriste.</strong>
        Ce document décrit fidèlement ce que la plateforme fait réellement aujourd'hui, et
        cite les articles de la Loi n°2013-450 relative à la protection des données à
        caractère personnel (texte officiel, ARTCI) qui s'appliquent. Il ne remplace pas
        un avis juridique et doit être relu par un conseil juridique / délégué à la
        protection des données avant toute publication officielle — notamment pour
        confirmer qu'une <strong>déclaration préalable auprès de l'Autorité de protection
        des données (ARTCI)</strong> a bien été effectuée pour ce traitement
        (article 5), ce que je ne peux pas vérifier moi-même.</span>
    </div> -->

    <h2>1. Qui collecte vos données ?</h2>
    <p>ACS Groupe, à travers la plateforme Presence, utilisée pour l'émargement des
    participants à ses événements, réunions et ateliers.</p>

    <h2>2. Quelles données sont collectées ?</h2>
    <p>Lorsque vous émargez à un événement via un QR code, nous collectons :</p>
    <ul>
        <li>Identité : nom, prénom, email</li>
        <li>Coordonnées professionnelles : téléphone, entreprise, direction, service, poste</li>
        <li>Preuve de présence : votre signature manuscrite (saisie à l'écran)</li>
        <li>Géolocalisation approximative de votre appareil au moment de l'émargement (confirme votre présence sur place ; jamais utilisée à d'autres fins, jamais suivie en continu)</li>
        <li>Horodatage de votre arrivée et, le cas échéant, de votre départ</li>
    </ul>

    <h2>3. Pourquoi ces données sont-elles collectées ?</h2>
    <ul>
        <li>Constituer la feuille de présence officielle de l'événement</li>
        <li>Vous envoyer un email récapitulatif après l'événement</li>
        <li>Vous reconnaître automatiquement si vous participez à un autre événement ACS Groupe (éviter de ressaisir vos informations)</li>
        <li>Prévenir les doublons et les émargements frauduleux (QR photographié à distance, etc.)</li>
    </ul>

    <h2>4. Qui a accès à ces données ?</h2>
    <p>Uniquement les comptes internes ACS Groupe autorisés (organisateurs et
    administrateurs de la plateforme). Vos données ne sont <strong>ni vendues, ni
    partagées avec un tiers</strong> en dehors d'ACS Groupe.</p>

    <h2>5. Combien de temps vos données sont-elles conservées ?</h2>
    <p>La loi (article 16) impose que les données ne soient conservées que pour la
    <strong>durée nécessaire aux finalités</strong> pour lesquelles elles ont été
    collectées — pas une durée fixe universelle, mais une durée proportionnée et
    justifiée. L'article 43 précise que cette durée est <strong>fixée par l'Autorité de
    protection des données</strong> (ARTCI) en fonction du type de traitement, dans le
    cadre de la déclaration préalable prévue à l'article 5.</p>
    <!-- <div class="draft">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 9v4M12 17h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/></svg>
        <span><strong>Point non tranché aujourd'hui.</strong> À date, la plateforme
        conserve techniquement les données indéfiniment (pas de purge automatique). Une
        durée de conservation conforme aux articles 16 et 43 doit être définie,
        déclarée à l'ARTCI et effectivement appliquée (purge automatique) avant
        publication officielle.</span>
    </div> -->

    <h2>6. Quels sont vos droits ?</h2>
    <p>Conformément aux articles 28 à 34 de la loi, vous avez le droit :</p>
    <ul>
        <li>D'être informé(e) des finalités du traitement et de la durée de conservation (article 28)</li>
        <li>D'accéder à vos données et d'obtenir la confirmation qu'elles sont traitées (article 29)</li>
        <li>De vous opposer, pour un motif légitime, au traitement de vos données (article 30)</li>
        <li>De faire rectifier, compléter ou mettre à jour des données inexactes (article 31)</li>
        <li>De demander l'effacement de vos données ("droit à l'oubli"), notamment lorsqu'elles ne sont plus nécessaires aux finalités poursuivies (article 33)</li>
    </ul>
    <p>Pour exercer ces droits, contactez
    <a href="mailto:ekissi@acsgroupe.ci">ekissi@acsgroupe.ci</a>
    <!-- <span class="maj">(adresse à confirmer)</span>.</p> -->

    <h2>7. Cookies</h2>
    <p>La plateforme utilise uniquement un cookie de session technique, nécessaire au
    bon fonctionnement du site (aucun cookie de mesure d'audience ou de publicité).</p>
</body>
</html>
