# Cadrage — Évolution « Multi-filiales + QR post-clôture »

> Document de référence partagé (agents UX, dev, sécu, QA, devops, doc). Toute
> décision non tranchée est signalée `❓ À CONFIRMER`. Aucune donnée n'est
> inventée : ce qui n'est pas décidé est listé en § 9, pas supposé.
> Ce cadrage **complète** `cadrage.md` (socle produit) ; il ne le remplace pas,
> sauf sur la décision Q14 (voir § 2).

- **Statut** : cadrage de l'évolution — **nommage et modèle SuperAdmin tranchés, reste à valider avec le client avant UX/dev**
- **Date** : 2026-07-25
- **Rédacteur** : agent `chef-de-projet`
- **Version** : 0.2 — Q-ME-1 et Q-ME-2 tranchées par le client
- **Périmètre** : holding ACS Groupe multi-filiales, rôles à 3 niveaux, QR post-clôture

---

## 1. Reformulation du besoin (ce que le client demande)

ACS Groupe n'est plus considéré comme une organisation plate : c'est une
**holding** composée de plusieurs **filiales**. Trois demandes :

1. **Multi-filiales** — pouvoir créer une filiale au sein de la holding, avec un
   **administrateur dédié** (« AdminFiliale ») qui gère **ses propres**
   utilisateurs et **son propre** paramétrage (types d'événements, branding).
2. **Super administrateur** (le client) — **vue et droits globaux** sur toutes
   les filiales ; peut ajouter ou retirer n'importe qui dans n'importe quelle
   filiale, et créer/supprimer des filiales.
3. **QR post-clôture avec délai de grâce** — un organisateur peut laisser le QR
   d'un événement **fonctionnel 15 minutes après l'heure de clôture**, puis plus
   aucune inscription n'est possible. Le **visiteur scanne lui-même** et suit le
   **parcours normal** (géoloc, formulaire, signature). Ce n'est **pas** un ajout
   manuel fait par l'organisateur.

## 2. Rupture avec l'existant : la décision Q14 devient caduque

Le socle actuel (`cadrage.md` § 2, `UserRole`, CLAUDE.md) documente **Q14 :
« aucun cloisonnement entre organisateurs — tout compte voit tous les
événements »**. La nouvelle demande impose **l'inverse** :

- **Cloisonnement par filiale** : un `Organisateur` et un `AdminFiliale` ne voient
  et ne gèrent **que** les événements, comptes et paramètres de **leur** filiale.
- **Exception** : le `SuperAdmin` voit et gère **tout**, transversalement.

> ✅ **Nouvelle décision produit (remplace Q14)** : le cloisonnement inter-filiales
> devient une **exigence** ; l'accès partagé global disparaît sauf pour le
> `SuperAdmin`. `cadrage.md` § 2, § 5.2 (F7), § 6 et l'enum `UserRole` doivent
> être mis à jour en conséquence lors de l'implémentation (T-ME-01).

## 3. Décisions déjà arbitrées avec le client (acquises — à documenter, pas à rediscuter)

| # | Décision | Conséquence de conception |
|---|---|---|
| D-ME-1 | **`Person` reste unifié au niveau holding** (clé = email normalisé), **sans** `filiale_id`. | Préserve l'anti-chevauchement (`activeOverlap`) qui dépend d'une identité `Person` unique. **Cloisonner `Person` casserait cette anti-fraude.** |
| D-ME-2 | **Migration auto d'une filiale par défaut** (« ACS Groupe »). Tous les comptes/événements existants y sont rattachés automatiquement. | Zéro action manuelle au déploiement ; réassignation possible ensuite par le `SuperAdmin`. |
| D-ME-3 | **Rôles à 3 niveaux** : `SuperAdmin` > `AdminFiliale` > `Organisateur`. | Étend l'enum `UserRole` (aujourd'hui `Admin` \| `Organisateur`). |
| D-ME-4 | **QR post-clôture** : fenêtre d'acceptation **étendue de 15 min** après l'heure de clôture, puis fermeture définitive. Parcours visiteur **inchangé**. | Extension de la fenêtre d'émargement + report de la clôture/email ; **pas** un ajout manuel. |
| D-ME-5 | **Nommage tranché (Q-ME-1)** : l'objet organisationnel s'appelle **« filiale »** (table `filiales`, modèle `Filiale`, colonne `filiale_id`). | Évite la collision avec le champ visiteur existant « Entité/Entreprise » (colonne `company`), qui reste inchangé et n'a **aucun rapport** avec cette notion. |
| D-ME-6 | **`SuperAdmin` tranché (Q-ME-2)** : `filiale_id` **nullable**, `NULL` pour le `SuperAdmin`. Pas de filiale « holding » fictive créée. | Sémantiquement correct (le SuperAdmin est au-dessus des filiales, pas une filiale de plus) ; évite qu'une fausse filiale « holding » pollue les listes/rapports par filiale. Coût : le scoping par filiale doit gérer explicitement le cas `filiale_id IS NULL` (déjà identifié comme piège principal, § 5.2). |

## 4. Modèle de données proposé (à valider par `senior-fullstack`)

### 4.1 Nouvelle table `filiales`

| Colonne | Type | Notes |
|---|---|---|
| `id` | PK | |
| `name` | string, unique | Nom de la filiale (ex. « ACS Immobilier »). |
| `slug` | string, unique | ❓ utile si URL/branding par filiale — à confirmer. |
| `is_active` | bool, défaut `true` | Filiale désactivée = ses comptes ne peuvent plus se connecter ❓. |
| `timestamps` | | |

> Le champ visiteur existant **« Entité/Entreprise »** (colonne `company` sur
> `Person`/`Attendance`, saisie libre par le visiteur pour indiquer sa société
> d'origine) est **sans rapport** avec la table `filiales` et **ne change pas**.
> Le nommage « filiale » a été choisi précisément pour éviter toute confusion
> entre les deux (D-ME-5).

### 4.2 Colonnes `filiale_id` ajoutées

| Table | Ajout | Nullable ? | Règle |
|---|---|---|---|
| `users` | `filiale_id` FK → `filiales` | Nullable | `NULL` pour le `SuperAdmin` (D-ME-6) ; `NOT NULL` en pratique pour `AdminFiliale`/`Organisateur` (contrainte applicative, pas SQL, pour ne pas bloquer le cas SuperAdmin). |
| `events` | `filiale_id` FK → `filiales` | NOT NULL (après backfill) | Portée de cloisonnement principale. Dénormalisé depuis le créateur pour permettre la réassignation. |
| `event_types` | `filiale_id` FK → `filiales` | NOT NULL (après backfill) | Types **par filiale** (D-ME-3 : l'AdminFiliale gère ses types). ⚠️ l'unicité `name` **globale** actuelle devient `unique(filiale_id, name)`. |
| `settings` | refonte `key`→`(filiale_id, key)` | — | Branding/paramétrage **par filiale**. ⚠️ Table aujourd'hui **globale** (PK = `key`). Voir § 6.3. |
| `people` | **AUCUN** `filiale_id` | — | **Décision D-ME-1** : référentiel unifié holding. |
| `attendances` | **AUCUN** `filiale_id` | — | Rattachée à `event` (qui porte la filiale) ; le snapshot suit l'événement. |

### 4.3 Ordre de migration (sans downtime, hébergement mutualisé)

1. Créer `filiales`, insérer la filiale par défaut « ACS Groupe ».
2. Ajouter `filiale_id` **nullable** sur `users`, `events`, `event_types`.
3. **Backfill** : tous les enregistrements existants → id de la filiale par défaut
   (y compris les comptes `Admin` actuels, qui deviennent `SuperAdmin` avec
   `filiale_id = NULL` lors de la migration du rôle — voir T-ME-01).
4. Passer `filiale_id` en **NOT NULL** + FK + index sur `events`, `event_types`
   (reste nullable sur `users` pour le cas `SuperAdmin`, D-ME-6).
5. Migrer `settings` vers un modèle scopé par filiale (rattacher l'existant à
   la filiale par défaut) — voir § 6.3.

## 5. Modèle d'autorisation proposé (à valider par `senior-fullstack` + `security-expert`)

### 5.1 Rôles (nouvel enum `UserRole`)

| Rôle | Portée | Droits |
|---|---|---|
| `SuperAdmin` | **Toutes** les filiales (`filiale_id = NULL`) | Crée/supprime des filiales ; ajoute/retire n'importe qui partout ; réassigne comptes/événements ; voit tout. |
| `AdminFiliale` | **Sa** filiale | Gère les `Organisateur` de sa filiale ; paramètre sa filiale (types, branding) ; voit/gère les événements de sa filiale. |
| `Organisateur` | **Sa** filiale | Crée/gère des événements de sa filiale ; ne voit **pas** les autres filiales ; **pas** d'accès Paramètres. |

> Le `label()` FR et un `isSuperAdmin()` / `isFilialeAdmin()` sont à ajouter.
> `isAdmin()` actuel (utilisé pour la gate Paramètres) doit être revu : les
> Paramètres deviennent accessibles à `AdminFiliale` **et** `SuperAdmin` (scopés
> différemment), plus au seul « admin ».

### 5.2 Deux niveaux de contrôle complémentaires

Le middleware `role` actuel (`EnsureUserRole`) filtre **par rôle** mais **ne
scope pas par filiale**. Il faut **les deux** :

1. **Filtrage par rôle** (existant, étendu) : `role:admin_filiale,super_admin` sur
   le groupe Paramètres, `role:super_admin` sur la gestion des filiales.
2. **Scoping par filiale** (nouveau) : garantir qu'une requête ne renvoie que les
   `events`/comptes de la filiale de l'utilisateur, **sauf** `SuperAdmin`
   (`filiale_id = NULL`, donc **aucun filtre appliqué** pour lui — pas un
   filtre `filiale_id = NULL` qui ne renverrait rien).

**Options pour le scoping (compromis à trancher par `senior-fullstack`, Q-ME-3) :**

- **(A) Global Scope Eloquent conditionnel** sur `Event` (et `User` admin) :
  applique automatiquement `where filiale_id = auth()->user()->filiale_id` **sauf
  si** `SuperAdmin` (ne pose aucun filtre dans ce cas).
  - ✅ Sûr par défaut (impossible d'oublier un `where` dans un contrôleur).
  - ⚠️ **Piège critique** : le scope **ne doit PAS** s'appliquer au contexte
    **non authentifié** (page publique `/e/{slug}`, `CloseDueEvents`,
    `queue:work`, exports système). Un global scope basé sur `auth()` est **null
    en CLI/cron** → il faut explicitement `withoutGlobalScope` dans les commandes,
    ou ne l'activer que sur le groupe de routes `admin`. Risque de régression sur
    le cron de clôture/email si mal cadré.
- **(B) Policies Laravel + scoping explicite** dans chaque contrôleur/requête
  admin (`->where('filiale_id', $user->filiale_id)` + `EventPolicy`).
  - ✅ Explicite, pas d'effet de bord CLI.
  - ⚠️ Répétitif, risque d'oubli sur une nouvelle route.
- **Recommandation `chef-de-projet`** (à confirmer par le dev) : **(A) restreint
  au groupe de routes `admin`** (via un middleware qui pose le scope), **doublé de
  Policies** sur les actions d'écriture sensibles (réassignation, gestion de
  comptes). Défense en profondeur, coût maîtrisé.

### 5.3 Surfaces à re-scoper (revue exhaustive)

| Surface | Fichier(s) | Impact |
|---|---|---|
| Dashboard | `DashboardController` | KPIs/listes filtrés par filiale (sauf SuperAdmin). |
| Liste événements | `EventController@index` | Scoping filiale. |
| Détail / présences / exports | `EventController@show`, `AttendanceController` (feed, export CSV/XLSX/PDF, badges) | Vérifier que `{event}` appartient à la filiale (Policy) ; **exports = fuite si non scopé**. |
| Statistiques globales | `StatisticsController` | Scoper par filiale ; SuperAdmin = sélecteur de contexte (Q-ME-6 tranchée), défaut « Toutes les filiales ». |
| Portfolio | `PortfolioController` | Scoping filiale. |
| **Annuaire participants** | `ParticipantController` | Historique scopé à la filiale (Q-ME-4 tranchée) : une filiale ne voit jamais l'historique de présence d'une personne dans une autre filiale. |
| Paramètres | `SettingsController`, `EventTypeController`, `AccountController` | AdminFiliale = scopé sa filiale ; SuperAdmin = global / sélection de filiale. |
| Gestion des filiales (nouveau) | à créer | `SuperAdmin` uniquement : CRUD filiales, réassignation comptes/événements. |
| **Page publique** | `PublicAttendanceController` | **NON scopée** (accès par slug, sans compte) — ne doit **pas** hériter du scope filiale. |
| **Cron** | `CloseDueEvents`, `queue:work` | **NON scopés** — doivent traiter **toutes** les filiales. |

## 6. QR post-clôture avec délai de grâce (conception détaillée)

### 6.1 Principe retenu (compatible mutualisé, sans nouveau cron)

Le mécanisme HMAC de `QrTokenService` **n'a pas besoin de changer** : les tokens
tournants dépendent de l'**horloge serveur** (fenêtres de 15 s), pas de la fenêtre
de l'événement ; le ticket de scan (TTL 5 min) reste valable. Le délai de grâce
est **purement une extension de la fenêtre d'acceptation** côté serveur.

- **Point de contrôle unique** : `Event::isOpenForCheckIn()` accepte les
  soumissions jusqu'à **`ends_at + 15 min`** (au lieu de `ends_at`), si le délai de
  grâce est activé pour l'événement.
- Introduire une méthode dérivée `Event::checkInClosesAt()` = `ends_at` (défaut)
  ou `ends_at + grâce` (si activé) ; `isOpenForCheckIn()` s'appuie dessus. Le
  `status()` dérivé (« Clos » dès `now > ends_at`) **ne change pas** : on
  **découple** « statut affiché Clos » de « émargement encore ouvert (grâce) ».

### 6.2 Articulation avec `CloseDueEvents` (le point délicat)

Aujourd'hui `CloseDueEvents` clôt (`closed_at`) **et envoie l'email récap** dès
`ends_at < now`. Or l'email récap **ne doit pas partir avant la fin du délai de
grâce** (sinon il manque les émargements de la grâce).

- ✅ **Solution sans second cron** : remplacer la condition de sélection
  `where('ends_at', '<', now)` par la **borne effective de clôture** =
  `checkInClosesAt() < now`. Pour un événement avec grâce, la clôture (pose de
  `closed_at`) **et** la mise en file de l'email sont ainsi **reportées** de 15 min
  automatiquement. Un seul déclencheur, idempotence préservée.
- Conséquence : pendant la grâce, `closed_at` est **null** (le cron n'a pas encore
  clos) et `isOpenForCheckIn()` renvoie `true` via la branche grâce. Cohérent.

### 6.3 Activation : opt-in par événement

Le besoin dit « l'organisateur doit **pouvoir** laisser le QR fonctionnel » → la
grâce est un **choix par événement**, désactivé par défaut.

- Proposition : colonne **`grace_check_in_enabled` (bool, défaut `false`)** sur
  `events`, durée = **constante 15 min** (valeur du brief).
- ❓ **Q-ME-5** : durée **fixe 15 min** pour tous, ou **configurable** par
  événement (`grace_period_minutes`) ? Le brief fixe 15 min ; par défaut on part
  sur une **constante** (YAGNI), configurabilité en réserve.

### 6.4 Cas limites à traiter

- **Clôture manuelle anticipée** : si l'organisateur clôt l'événement à la main
  (`EventLifecycleController`) pendant/avant la grâce, `closed_at` est posé →
  `isOpenForCheckIn()` renvoie `false`. **Décision par défaut proposée** : la
  clôture manuelle **prime** sur la grâce (l'organisateur ferme volontairement).
  ❓ **Q-ME-7** à confirmer.
- **Mode QR tournant en grâce** : la projection tournante suppose un écran encore
  allumé. En pratique, le cas d'usage post-clôture concerne surtout le **QR
  statique imprimé**. Le tournant reste techniquement supporté (tokens continuent
  de tourner sur l'horloge serveur) mais dépend d'un écran disponible — à noter
  pour l'UX (message « émargement encore ouvert 15 min »).
- **Anti-chevauchement pendant la grâce** : `activeOverlap()` ne considère comme
  « active » qu'une présence dont l'événement couvre `now` (`starts_at ≤ now ≤
  ends_at`). Un émargement en grâce (donc `now > ends_at`) **ne bloquera pas** un
  autre événement, et un autre événement en cours ne sera pas détecté comme
  chevauchant l'événement en grâce. Comportement **acceptable** (la grâce est un
  rattrapage court) mais **à documenter** ; ne pas « corriger » sans besoin.
- **Géoloc/geofence** : inchangés — le parcours visiteur est strictement le même.

## 7. Modes d'échec & risques

| # | Risque | Impact | Prob. | Mitigation |
|---|---|---|---|---|
| RME-1 | **Fuite inter-filiales** (oubli d'un `where filiale_id` sur une route/export) | Élevé (confidentialité) | Moyenne | Scope par middleware sur groupe `admin` + Policies + **tests d'autorisation** systématiques (accès croisé filiale A→B refusé). Gate `security-expert`. |
| RME-2 | **Global scope appliqué au cron/public** → clôtures/emails cassés ou page publique filtrée | Élevé | Moyenne | Scope **restreint au groupe `admin`** ; tests explicites sur `CloseDueEvents` et `/e/{slug}` multi-filiales. |
| RME-3 | **Backfill de migration** échoue ou laisse des `filiale_id` NULL sur `events`/`event_types` → 500 en prod | Élevé | Faible | Migration en 5 étapes (§ 4.3), NOT NULL seulement après backfill vérifié ; test de migration sur copie. |
| RME-4 | *(résolu — D-ME-5)* ~~Collision terminologique « entité » (org) vs « Entité/Entreprise » (visiteur)~~ | — | — | Nommage « filiale » retenu, plus de collision. |
| RME-5 | **Refonte `settings` globale → par filiale** casse le branding existant | Moyen | Moyenne | Migration scopée + toggle « hériter branding holding » (Q-ME-8 tranchée) ; fuseau/format date restent globaux. |
| RME-6 | **Email récap envoyé avant fin de grâce** (émargements manquants) | Moyen | Moyenne | Borne effective `checkInClosesAt()` dans `CloseDueEvents` (§ 6.2) + test. |
| RME-7 | *(résolu — D-ME-6)* `filiale_id` nullable pour `SuperAdmin` : le scoping doit traiter explicitement `IS NULL` comme « aucun filtre », pas comme un filtre qui ne renvoie rien | Moyen | Moyenne | À couvrir par un test dédié (`SuperAdmin` voit bien tout, `AdminFiliale`/`Organisateur` avec `filiale_id` NULL par erreur ne voient rien — fail-closed). |
| RME-8 | **Annuaire participant** expose l'historique cross-filiale d'une personne | Moyen (confidentialité) | Moyenne | Trancher Q-ME-4 ; par défaut, scoper l'historique affiché à la filiale. |

## 8. Critères de succès de l'évolution

- Un `SuperAdmin` crée une filiale, y crée un `AdminFiliale`, qui à son tour crée
  un `Organisateur` — chacun ne voit **que** le périmètre autorisé.
- Un `Organisateur` de la filiale A **ne peut ni voir ni exporter** un événement
  de la filiale B (vérifié par test d'accès croisé, y compris sur les URLs
  d'export).
- Le `SuperAdmin` (`filiale_id = NULL`) voit et réassigne comptes/événements
  entre filiales, sans qu'aucun filtre ne le limite.
- Après déploiement, **zéro action manuelle** : la filiale « ACS Groupe » existe,
  tout l'existant y est rattaché, la plateforme fonctionne comme avant pour les
  comptes actuels.
- Un visiteur scanne un QR **jusqu'à 15 min après l'heure de clôture** (si la grâce
  est activée) et émarge via le parcours normal ; **à 15 min + 1 s, refus**.
- L'**email récap** part **après** la fin de la grâce et inclut les émargements de
  la grâce.
- L'anti-chevauchement et l'unicité `Person` (identité holding) restent intacts.
- Gate `security-expert` passée (isolation inter-filiales) avant déploiement.

## 9. Questions ouvertes (à trancher par le client / les agents — RIEN n'est supposé)

| # | Question | Nature | Qui décide | Bloquant ? |
|---|---|---|---|---|
| Q-ME-1 | ~~Nommage de l'objet organisationnel~~ | — | — | **Tranché** : « filiale » (D-ME-5). |
| Q-ME-2 | ~~`SuperAdmin` : `filiale_id = NULL` ou filiale « holding » dédiée ?~~ | — | — | **Tranché** : `NULL` (D-ME-6). |
| Q-ME-3 | Scoping : Global Scope (groupe admin) vs Policies explicites vs les deux ? | Conception | **senior-fullstack** | Non (arbitrage dev) |
| Q-ME-4 | ~~Annuaire participant : historique cross-filiale ?~~ | — | — | **Tranché** : scopé à la filiale (pas d'historique cross-filiale visible). |
| Q-ME-5 | Délai de grâce **fixe 15 min** ou **configurable** par événement ? | Produit | client | Non (défaut : fixe 15 min) |
| Q-ME-6 | ~~`SuperAdmin` : agrégat holding ou sélecteur de filiale ?~~ | — | — | **Tranché** : sélecteur de contexte persistant en topbar (dashboard/événements/stats/portfolio/paramètres), option **« Toutes les filiales »** en défaut. AdminFiliale/Organisateur : badge de contexte figé, pas de bascule. |
| Q-ME-7 | Clôture **manuelle** anticipée : prime-t-elle sur le délai de grâce (ferme le QR) ? | Produit | client | Non (défaut : oui) |
| Q-ME-8 | ~~`settings` par filiale : repli holding ? réglages globaux (fuseau, format date) globaux ou par filiale ?~~ | — | — | **Tranché** : branding — toggle « hériter du branding holding » par filiale (repli si non défini). Fuseau horaire et format de date **restent globaux pour toutes les filiales**, jamais surchargeables. |
| Q-ME-9 | Un utilisateur peut-il appartenir à **plusieurs** filiales ? | Modèle / auth | client | Non (défaut : une seule) |
| Q-ME-10 | ~~Types d'événements par filiale uniquement, ou jeu partagé holding en plus ?~~ | — | — | **Tranché** : uniquement par filiale. Pas de socle partagé holding. |
| Q-ME-11 | Message visiteur pendant la grâce (« émargement encore ouvert 15 min après la fin ») — libellé et affichage. | UX | ux-designer | Non |

> **Conclusion** : les décisions structurantes (cloisonnement par filiale, 3 rôles,
> `Person` unifié, migration auto, mécanisme de grâce sans nouveau cron, nommage
> « filiale », `SuperAdmin` en `filiale_id = NULL`) sont **toutes posées**. Plus
> aucun point bloquant avant UX/dev. Les questions restantes (Q-ME-3 à Q-ME-11)
> sont non bloquantes (déléguées aux agents ou confirmations produit à défaut
> sûr) et peuvent se trancher en parallèle du lancement UX/dev.
