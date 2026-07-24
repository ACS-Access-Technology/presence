# Déploiement Hostinger — notes d'infrastructure

> Ce que le CI/CD (`.github/workflows/ci-cd.yml`) ne peut pas faire tout seul
> sur cet hébergement mutualisé, et pourquoi.

## Contexte

- Domaine : `plum-goose-405223.hostingersite.com` (sous-domaine Hostinger
  temporaire — à remplacer par un vrai domaine ACS Groupe si besoin).
- Code déployé dans : `~/domains/plum-goose-405223.hostingersite.com/presence`
  (hors du webroot, protégé par un `.htaccess` `Require all denied`).
- `public_html` est un **symlink** vers `presence/public` (cet hébergement
  n'offre aucune option de document root personnalisé dans hPanel — vérifié
  exhaustivement : Pack d'hébergement, Site web, Noms de domaine, Avancé).
- PHP CLI en SSH : `/opt/alt/php83/usr/bin/php` (PHP 8.3, celui qu'utilisent
  aussi la CI et `composer.json`).

## Limitations CLI confirmées sur cet hébergement

Ces actions ont été testées en SSH et échouent — pas un bug de script, une
restriction de la plateforme. Doivent être faites via hPanel (web) :

1. **Document root** : aucune option pour pointer un domaine vers un
   sous-dossier autre que `public_html` → contourné avec le symlink ci-dessus
   (déjà en place, rien à refaire).
2. **Cron jobs** : `crontab` absent du `$PATH` en SSH (`bash: crontab: command
   not found`). Doit être créé via **hPanel → Avancé → Cron Jobs**.

## Cron à créer (planificateur Laravel — clôture + emails)

**Une seule entrée cron** appelle `schedule:run`, qui pilote lui-même les deux
tâches définies dans `routes/console.php` :

- `events:close-due` — clôture les événements terminés et met en file les
  emails de confirmation (`AttendanceConfirmationMail`, `ShouldQueue`).
- `queue:work --stop-when-empty --max-time=50` — vide la file puis s'arrête
  (pas de worker permanent, généralement interdit sur mutualisé).

⚠️ **Ne pas** créer un cron qui appelle `queue:work` directement : sans
`events:close-due`, aucun événement n'est jamais clôturé et aucun email
récap n'est jamais mis en file — l'échec est silencieux (le statut affiché
reste correct car dérivé de `ends_at`, seul l'email ne part jamais).

Dans hPanel → Avancé → Cron Jobs → créer :

- **Fréquence** : toutes les minutes (`* * * * *`)
- **Commande** :
  ```
  cd /home/u928962285/domains/plum-goose-405223.hostingersite.com/presence && /opt/alt/php83/usr/bin/php artisan schedule:run >> /dev/null 2>&1
  ```

## SMTP (emails réels)

Voir la fiche séparée pour la création de l'adresse email et récupération
des identifiants SMTP dans hPanel.
