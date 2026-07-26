# Checklist — vérification du serveur de production (Hostinger)

> À faire une seule fois manuellement (connexion SSH ou hPanel), puis à
> revérifier après tout changement d'hébergement. Je ne peux pas exécuter ces
> vérifications moi-même : pas d'accès SSH au serveur réel.

## 0. Déclaration préalable auprès de l'ARTCI (article 5 de la Loi n°2013-450)

Trouvé en lisant le texte officiel de la loi (`docs/gestion-projet/recette-metier.md`
ne le couvrait pas encore) : **tout traitement de données à caractère personnel doit
faire l'objet d'une déclaration préalable auprès de l'Autorité de protection
(ARTCI)** avant sa mise en œuvre (article 5). Cette déclaration doit notamment
préciser la durée de conservation envisagée (article 9), et c'est l'ARTCI qui fixe
au final cette durée par type de traitement (article 43).

**Point à vérifier / action à mener** : cette déclaration a-t-elle déjà été
déposée pour Presence ? Si non, c'est une démarche administrative (pas
technique) à faire auprès de l'ARTCI avant une ouverture large au grand public —
au-delà de la simple relecture juridique de la page `/confidentialite`.

## 1. `APP_DEBUG` doit être `false`

```bash
grep APP_DEBUG /chemin/vers/presence/.env
```

**Attendu :** `APP_DEBUG=false`. Si c'est `true` en prod, la moindre erreur
affiche la stack trace complète (chemins serveur, requêtes SQL, parfois des
secrets en clair) à **n'importe quel visiteur du site**. C'est le point le
plus critique de cette liste.

## 2. `APP_ENV` doit être `production`

```bash
grep APP_ENV /chemin/vers/presence/.env
```

Certains comportements Laravel (cache de vue, messages d'erreur génériques)
dépendent de cette valeur, indépendamment de `APP_DEBUG`.

## 3. HTTPS forcé partout

- Ouvrir `http://plum-goose-405223.hostingersite.com/` (sans `s`) dans un
  navigateur : doit rediriger automatiquement vers `https://`.
- Si non : ajouter la redirection dans le `.htaccess` racine (`public_html`
  est un symlink vers `presence/public`, voir `deploiement-hostinger.md`).

## 4. Cookies de session réellement sécurisés

```bash
grep SESSION_SECURE_COOKIE /chemin/vers/presence/.env
```

**Attendu :** `SESSION_SECURE_COOKIE=true` (le cookie de session ne doit
jamais transiter en clair, même par erreur de config HTTPS ailleurs).

## 5. Sauvegardes de la base de données

- hPanel → Bases de données → vérifier qu'une sauvegarde automatique
  (quotidienne a minima) est bien active pour la base `presence`.
- Télécharger une sauvegarde manuellement une fois pour confirmer qu'elle
  est exploitable (pas juste "activée" en apparence).
- Noter ici la fréquence réelle une fois vérifiée : `❓ à compléter`.

## 6. Le cron `schedule:run` tourne réellement

```bash
# Dans les logs Laravel, vérifier des entrées régulières (toutes les minutes)
tail -f /chemin/vers/presence/storage/logs/laravel.log
```

Sans ce cron actif, **aucun email de confirmation ne part jamais** (voir
`deploiement-hostinger.md`). À vérifier après CHAQUE intervention sur
l'hébergement (un cron hPanel peut être silencieusement désactivé lors d'une
maintenance Hostinger).

## 7. Domaine

- Le site tourne actuellement sur `plum-goose-405223.hostingersite.com`
  (sous-domaine technique Hostinger), pas un domaine ACS Groupe.
- Décision produit à prendre : achat/configuration d'un vrai domaine
  (`presence.acsgroupe.ci` ou équivalent) avant ouverture au grand public —
  un sous-domaine `hostingersite.com` inspire peu confiance à un visiteur
  externe qui scanne un QR pour la première fois.

## 8. `SENTRY_LARAVEL_DSN`

Une fois un projet Sentry créé (gratuit jusqu'à un certain volume) :

```bash
echo 'SENTRY_LARAVEL_DSN=https://xxxxx@xxxx.ingest.sentry.io/xxxxx' >> /chemin/vers/presence/.env
php artisan config:cache
```

Sans cette variable, le SDK installé ne fait rien (pas d'erreur, juste
inactif) — donc rien ne casse tant qu'elle n'est pas renseignée, mais rien
n'est surveillé non plus.

## 9. Secret GitHub `PROD_URL`

✅ Déjà fait (confirmé) — utilisé par le smoke test post-déploiement
(`.github/workflows/ci-cd.yml`).
