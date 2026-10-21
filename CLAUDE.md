# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Projet

**Presence** — plateforme web **interne à ACS Groupe** (mono-tenant, pas un SaaS) pour l'émargement des visiteurs à des événements via QR code. Un organisateur crée un événement et génère un QR (statique ou tournant) ; les visiteurs le scannent depuis leur mobile — **sans app, sans compte** — remplissent un formulaire, sont géolocalisés et signent à la main. À la clôture, un email récapitulatif part automatiquement. Le suivi de projet vit dans `docs/gestion-projet/` (`cadrage.md` fait foi sur les décisions produit ; `journal.md` tenu à jour).

Communication utilisateur **en français** (voir instructions globales). Code, commits, identifiants restent dans leur langue d'origine. **Pas de co-auteur Claude** dans les commits/PR.

## Stack

- **Laravel ^13.8**, **PHP ^8.3** (plateforme figée à 8.3.0 dans `composer.json` — cible Hostinger mutualisé, `/opt/alt/php83`)
- **PHPUnit ^12** (pas Pest, malgré le plugin autorisé)
- **Vite 8 + Tailwind 4** compilent uniquement `resources/js/app.js` et `resources/css/app.css`
- MySQL en prod, **SQLite `:memory:`** en test
- `barryvdh/laravel-dompdf` (PDF), `maatwebsite/excel` (XLSX), `endroid/qr-code` (QR)

## Commandes

```bash
composer dev          # tout-en-un : serve + queue:listen + pail (logs) + vite
composer test         # config:clear puis php artisan test
php artisan test --filter=NomDuTest              # un seul test
php artisan test tests/Feature/PublicAttendanceTest.php  # un fichier
./vendor/bin/pint     # formatage (Laravel Pint) — lancer avant commit
npm run build         # build assets de prod
```

## Architecture

### Deux surfaces distinctes

1. **Public** (`/e/{slug}`, `Public\PublicAttendanceController`) — émargement sans compte, appelé en **fetch JSON** (les erreurs de validation sont rendues en JSON via `bootstrap/app.php` → `shouldRenderJsonWhen`).
2. **Admin** (`/admin/*`, middleware `auth`) — dashboard organisateur. Sous-groupe `role:admin` pour les Paramètres (types d'événement, comptes, branding). Rôles : `Admin` | `Organisateur` (`App\Enums\UserRole`). **Q14 : aucun cloisonnement entre organisateurs** — tout compte voit tous les événements.

Middleware `role` = `EnsureUserRole`, aliasé dans `bootstrap/app.php` (app minimale Laravel 11+, pas de `Kernel.php`).

### QR sans état — `QrTokenService` (clé de voûte anti-fraude)

Aucune table, aucun cron, aucun worker pour le QR (contrainte hébergement mutualisé). Tout est HMAC :
- **QR tournant** : `token = HMAC(secret, event|window)`, fenêtre de **15 s**. L'écran de projection poll `currentToken()` ~1×/s. `verifyToken()` accepte fenêtre courante **et précédente** (dérive d'horloge). L'**horloge serveur fait foi**.
- Au scan, un **ticket de scan signé** (`issueScanTicket`, TTL **5 min**) est émis ; c'est *lui* qui est vérifié à la soumission, pas le token brut (qui serait déjà expiré le temps de remplir le formulaire).
- **QR statique** : anti-fraude = géoloc + fenêtre temporelle + anti-doublon (pas de token tournant).
- `qr_secret` par événement (repli sur `app.key`), `$hidden` sur le modèle. `qr_mode` **verrouillé dès qu'une présence existe**.

### Présences — `AttendanceService`

- **Anti-doublon** : contrainte `UNIQUE(event_id, person_id)` en base ; `register()` est **idempotent** (2e soumission → présence existante, même référence).
- **Anti-chevauchement** : `activeOverlap()` détecte une présence active (sans `departed_at`) sur un autre événement dont la fenêtre couvre l'instant ; l'utilisateur confirme un départ (`$departFrom`).
- Référentiel **`Person`** unifié (clé = email normalisé) partagé par tout ACS Groupe. `PersonSource` : émargement / import / manuel. `is_staff` jamais dégradé par un upsert.
- Signature manuscrite stockée via `SignatureStorage` ; incluse dans les exports XLSX/PDF (colonne image).

### Statut d'événement — dérivé, jamais stocké

`Event::status()` calcule `annulé › clos › en cours › à venir` depuis l'horloge et les marqueurs `cancelled_at` / `closed_at` (`App\Enums\EventStatus`). Ne pas ajouter de colonne `status`.

### Planification sans worker persistant (hébergement mutualisé)

Une seule entrée cron (`* * * * * php artisan schedule:run`) pilote `routes/console.php` :
- `events:close-due` (chaque minute) — clôt les événements terminés + met en file les emails. **Idempotent** (jamais de double clôture/email).
- `queue:work --stop-when-empty --max-time=50` — vide la file puis s'arrête (pas de worker permanent). Les emails (`AttendanceConfirmationMail`) sont `ShouldQueue` / `QUEUE_CONNECTION=database` : **sans ce cron, aucun email ne part**.

### Assets front — deux régimes

- `resources/` → compilé par Vite (`@vite` dans les layouts).
- `public/css/*` et `public/js/*` → CSS/JS **écrits à la main** (vanilla, un fichier par écran : `event-detail.js`, `settings.js`…), servis via le helper **`versioned_asset()`** (`app/Support/helpers.php`) qui ajoute `?v=<mtime>` pour casser le cache navigateur à chaque déploiement. Un fichier public modifié **doit** passer par `versioned_asset()`, jamais `asset()` nu.

## Conventions

- `declare(strict_types=1)` en tête de chaque fichier PHP ; classes de service/export en `final`.
- Requêtes validées dans `app/Http/Requests/` (trait partagé `Concerns\ValidatesSignature`).
- Logique métier dans `app/Services/`, pas dans les contrôleurs.
- Enums PHP natifs avec méthode `label()` pour l'affichage FR.
- Docblocks explicatifs riches en français (le *pourquoi*, cas limites, contraintes plateforme) — suivre ce style existant.

## Déploiement

CI/CD GitHub Actions (`.github/workflows/`) : tests PHPUnit sur SQLite `:memory:` puis deploy sur `main`. Cible = Hostinger mutualisé. Contraintes plateforme documentées dans `docs/gestion-projet/deploiement-hostinger.md` : `public_html` est un **symlink** vers `presence/public`, cron créé via hPanel (pas de `crontab` en SSH), document root non configurable.
