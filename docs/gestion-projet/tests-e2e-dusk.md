# Tests E2E (Laravel Dusk)

Parcours navigateur réels (vrai Chrome, vraie application servie) — ce que les
tests Feature ne peuvent pas prouver (interactions JS pures, vrai formulaire de
connexion, etc.).

## Portée actuelle

- `tests/Browser/AdminLoginTest.php` — connexion réelle (bon et mauvais mot de
  passe) via le formulaire `/connexion`.
- `tests/Browser/PortfolioGalleryBrowserTest.php` — ouverture de la
  visionneuse photo du Portfolio, navigation précédente/suivante, fermeture.

**Non couvert volontairement pour l'instant** : le parcours public
d'émargement (QR → formulaire → géolocalisation → signature) nécessiterait de
simuler la géolocalisation navigateur (API CDP dédiée), plus complexe à fiabiliser
— à ajouter si un incident sur ce parcours le justifie.

## Pourquoi une configuration à part (`.env.dusk.local`)

Dusk pilote un **vrai navigateur contre un vrai serveur HTTP**, dans un
processus séparé du test PHPUnit qui écrit les données. `RefreshDatabase`
(utilisé partout ailleurs, SQLite `:memory:`) ne fonctionne pas ici : la
mémoire n'est pas partagée entre deux processus. Il faut donc :

1. Une base de test **persistée sur disque** (`database/dusk.sqlite`, jamais
   commitée — voir `.gitignore`), partagée par le serveur et par Dusk.
2. `DatabaseTruncation` (pas `DatabaseMigrations`) : migrer une fois puis
   tronquer entre les tests. `DatabaseMigrations` rejoue `down()`/`up()` à
   chaque test, ce qui fait planter certaines migrations `ALTER TABLE`
   historiques sous SQLite (incompatibilité SQLite, pas un bug Dusk).
3. `DatabaseTruncation` tronque **aussi** `filiales` sans rejouer l'insertion
   de la filiale par défaut faite par la migration — chaque test Dusk la
   recrée explicitement dans son `setUp()` (`Filiale::firstOrCreate(...)`).

## Lancer les tests Dusk en local

```bash
# 1. Créer la base de test si absente
touch database/dusk.sqlite

# 2. Démarrer un serveur DÉDIÉ (ne touche pas au serveur de dev habituel),
#    avec les variables d'environnement de .env.dusk.local
set -a && source .env.dusk.local && set +a
php artisan serve --port=8001 &

# 3. Faire correspondre le ChromeDriver à la version de Chrome installée
php artisan dusk:chrome-driver --detect

# 4. Lancer les tests (bascule automatiquement .env → .env.dusk.local le
#    temps du run, puis restaure .env)
php artisan dusk

# 5. Arrêter le serveur dédié
lsof -ti:8001 | xargs kill
```

## Pièges rencontrés (pour ne pas les redécouvrir)

- **Cookies persistants entre méthodes de test** : Dusk garde la session du
  navigateur d'une méthode `test_*` à l'autre dans la même classe. Un test qui
  se connecte puis un autre qui visite `/connexion` en attendant un formulaire
  vide échouera (redirection silencieuse car déjà authentifié) sans
  `$browser->logout()` explicite en début de test.
- **`keys($selector, ...)`** : le résolveur d'éléments Dusk préfixe déjà
  `body` par défaut. Appeler `->keys('body', '{escape}')` donne le sélecteur
  littéral invalide `body body`. Cibler un élément réel et visible (ex. le
  bouton fermer de la modale) au lieu de `'body'`.

## CI/CD

**Pas encore intégré au pipeline GitHub Actions.** Chrome/ChromeDriver
diffèrent entre ce Mac et le runner `ubuntu-latest`, et une suite Dusk
naissante qui bloquerait le déploiement mérite d'abord de prouver sa stabilité
en local sur plusieurs exécutions avant de conditionner un déploiement
production dessus. À activer explicitement quand la couverture E2E sera jugée
suffisante.
