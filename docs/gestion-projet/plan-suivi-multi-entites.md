# Plan de suivi — Évolution « Multi-filiales + QR post-clôture »

- **Statut** : plan de lots — **J-ME-0 franchi (Q-ME-1 et Q-ME-2 tranchées), prêt pour lancement UX/dev**
- **Date** : 2026-07-25
- **Rédacteur** : agent `chef-de-projet`
- **Méthodo** : **Scrumban léger inchangé** (voir `methodologie.md`). Cette
  évolution = **un épic** découpé en lots séquencés ; pas de changement de cadence
  ni de rituels. La chaîne d'équipe reste `ux-designer` → `senior-fullstack` →
  `qa-testeur` → `security-expert` → `devops-sre` → `redacteur-technique`.

> Référence : `cadrage-multi-entites.md` (décisions, modèle, autorisations,
> questions ouvertes). `journal.md` = suivi vivant.

---

## 1. Séquencement (dépendances)

```
J-ME-0  Validation client (Q-ME-1 nommage = filiale, Q-ME-2 SuperAdmin = filiale_id NULL) ── FAIT
   │
   ├─► Lot A  Fondations données (filiales + filiale_id + backfill)
   │      │
   │      ├─► Lot B  Autorisation (rôles + scoping + policies)
   │      │      │
   │      │      ├─► Lot C  Re-scoping des surfaces admin (dashboard, events, exports…)
   │      │      └─► Lot D  Gestion des filiales & comptes (SuperAdmin + AdminFiliale)
   │      │
   │      └─► Lot F  Paramétrage par filiale (settings + types + branding)
   │
   └─► Lot E  QR post-clôture (délai de grâce)  ── indépendant des lots A-D,
              peut avancer en parallèle (ne touche pas au cloisonnement)

Lot G  QA d'isolation + Gate sécurité  ── après C/D/F
Lot H  Migration/déploiement + doc     ── en dernier
```

Le **Lot E (QR post-clôture)** est **découplé** du multi-filiales : il peut être
développé et livré indépendamment (utile si le client veut cette valeur vite).

## 2. Backlog de l'évolution

> `[P0]` = cœur de l'évolution · `[P1]` = confort/ultérieur.
> Flux : Prêt → En cours → Revue (code+QA) → Gate sécurité → Fait.

### Lot A — Fondations données

| ID | Item | Prio | Acteur | Critères d'acceptation |
|---|---|---|---|---|
| T-ME-01 | Table `filiales` (nommage tranché D-ME-5) + modèle `Filiale` + filiale par défaut « ACS Groupe » | P0 | fullstack | Migration crée la table et insère la filiale par défaut ; modèle `final`, `strict_types`. |
| T-ME-02 | Migrations `filiale_id` (users, events, event_types) en 5 étapes (§ 4.3 cadrage) : nullable → backfill → NOT NULL + FK + index (reste nullable sur `users` pour le cas SuperAdmin, D-ME-6) | P0 | fullstack/sécu | Aucun `filiale_id` NULL après migration sur `events`/`event_types` ; existant rattaché à la filiale par défaut ; test de migration sur copie. |
| T-ME-03 | Relations Eloquent (`User↔Filiale`, `Event↔Filiale`, `EventType↔Filiale`) ; `Person` **inchangé** (pas de `filiale_id`) | P0 | fullstack | `Person` confirmé sans `filiale_id` (D-ME-1). |

### Lot B — Autorisation

| ID | Item | Prio | Acteur | Critères d'acceptation |
|---|---|---|---|---|
| T-ME-04 | Enum `UserRole` étendu (`SuperAdmin`, `AdminFiliale`, `Organisateur`) + `label()`, `isSuperAdmin()`, `isFilialeAdmin()` ; revoir `isAdmin()` | P0 | fullstack/sécu | Migration de la valeur `admin` existante → `SuperAdmin` avec `filiale_id = NULL` (D-ME-6) lors du backfill. |
| T-ME-05 | Mécanisme de **scoping par filiale** (arbitrage Q-ME-3 : global scope groupe admin + policies) | P0 | **fullstack/sécu** | Le cron `CloseDueEvents`, `queue:work` et la page publique `/e/{slug}` **ne sont pas** scopés (tests dédiés RME-2). `SuperAdmin` (`filiale_id NULL`) = aucun filtre appliqué, pas un filtre qui ne renvoie rien (RME-7). |
| T-ME-06 | `EventPolicy` (+ policies comptes/paramètres) : `view/update/export` refusés hors filiale, autorisés pour `SuperAdmin` | P0 | fullstack/sécu | Accès croisé filiale A→B = 403, y compris URLs d'export. |
| T-ME-07 | Middleware `role` étendu aux nouveaux rôles + gates de routes (`role:super_admin` pour gestion filiales, `role:admin_filiale,super_admin` pour Paramètres) | P0 | fullstack | Routes protégées ; `EnsureUserRole` mis à jour. |

### Lot C — Re-scoping des surfaces admin

| ID | Item | Prio | Acteur | Critères d'acceptation |
|---|---|---|---|---|
| T-ME-08 | Scoping Dashboard / liste événements / détail / présences | P0 | fullstack | KPIs et listes filtrés par le contexte filiale (sélecteur topbar, SuperAdmin ; badge figé, AdminFiliale/Organisateur). Défaut SuperAdmin = « Toutes les filiales » (Q-ME-6 tranchée). |
| T-ME-09 | **Exports** (CSV/XLSX/PDF/badges) scopés par filiale | P0 | fullstack/sécu | Un export ne sort jamais de données d'une autre filiale (RME-1). |
| T-ME-10 | Statistiques + Portfolio scopés | P0 | fullstack/ux | Sélecteur de contexte filiale (Q-ME-6 tranchée) ; vue « Toutes » = agrégat **ventilé par filiale** (barres/table), pas juste des totaux (réserve UX signalée par `ux-designer`). |
| T-ME-11 | Annuaire participants scopé à la filiale (Q-ME-4 tranchée) | P0 | fullstack | Aucun historique cross-filiale visible, y compris pour une même personne du référentiel `Person` unifié. |

### Lot D — Gestion des filiales & des comptes

| ID | Item | Prio | Acteur | Critères d'acceptation |
|---|---|---|---|---|
| T-ME-12 | CRUD **filiales** (SuperAdmin uniquement) | P0 | fullstack/ux | Créer/renommer/désactiver une filiale. |
| T-ME-13 | Gestion des comptes **scopée** : AdminFiliale crée des `Organisateur` dans sa filiale ; SuperAdmin partout + **réassigne un compte** d'une filiale à une autre | P0 | fullstack/sécu | `AccountController` scopé ; un AdminFiliale ne crée pas hors de sa filiale ; action « Réassigner » (SuperAdmin) change `filiale_id` du compte, avertit que les événements déjà créés ne suivent pas automatiquement (cf. T-ME-14 retiré). |
| ~~T-ME-14~~ | ~~Réassignation d'un **événement** d'une filiale à une autre~~ | — | — | **Retiré du périmètre** (2026-07-25) : pas de besoin réel identifié pour le moment (YAGNI). La réassignation de **compte** (déplacer un utilisateur entre filiales) reste couverte par T-ME-13/l'écran Comptes. À rouvrir si un besoin concret apparaît. |

### Lot E — QR post-clôture (délai de grâce) — *indépendant*

| ID | Item | Prio | Acteur | Critères d'acceptation |
|---|---|---|---|---|
| T-ME-15 | Colonne `grace_check_in_enabled` (bool, défaut `false`) sur `events` + choix à la création/édition | P0 | fullstack/ux | Opt-in par événement ; durée = constante 15 min (Q-ME-5). |
| T-ME-16 | `Event::checkInClosesAt()` + `isOpenForCheckIn()` étendus (branche grâce) ; **`status()` inchangé** | P0 | fullstack | Émargement accepté jusqu'à `ends_at + 15 min` si activé ; refusé après. |
| T-ME-17 | `CloseDueEvents` : sélection sur borne effective `checkInClosesAt()` (report clôture + email de 15 min) | P0 | fullstack | Email récap parti **après** la grâce, inclut les émargements de grâce ; idempotence préservée (RME-6). |
| T-ME-18 | Cas limites : clôture manuelle prime (Q-ME-7) ; message visiteur « ouvert 15 min » (Q-ME-11) | P0 | fullstack/ux | Comportements documentés + testés. |

### Lot F — Paramétrage par filiale

| ID | Item | Prio | Acteur | Critères d'acceptation |
|---|---|---|---|---|
| T-ME-19 | Refonte `settings` scopés par filiale (`(filiale_id, key)`) + migration de l'existant vers la filiale par défaut | P0 | fullstack/sécu | Branding existant préservé ; repli holding si non défini (Q-ME-8). |
| T-ME-20 | `event_types` par filiale (unicité `(filiale_id, name)`) + CRUD scopé | P0 | fullstack | AdminFiliale gère ses types ; Q-ME-10 (jeu partagé holding ?) tranché avant. |
| T-ME-21 | Branding par filiale appliqué à la page publique et aux exports | P1 | fullstack/ux | Le branding affiché correspond à la filiale de l'événement. |

### Lot G — QA & sécurité

| ID | Item | Prio | Acteur | Critères d'acceptation |
|---|---|---|---|---|
| T-ME-22 | Suite de tests **d'isolation inter-filiales** (accès croisé refusé sur toutes les surfaces + exports) | P0 | qa | Chaque route admin testée A→B = refus ; SuperAdmin = accès. |
| T-ME-23 | Tests QR post-clôture (limite exacte 15 min, email après grâce, clôture manuelle) | P0 | qa | Cas limites verts. |
| T-ME-24 | **Gate sécurité** : audit isolation, global scope vs cron/public, escalade de privilège inter-rôles | P0 | **security-expert** | 0 finding critique/haut ouvert. |

### Lot H — Migration & déploiement

| ID | Item | Prio | Acteur | Critères d'acceptation |
|---|---|---|---|---|
| T-ME-25 | Répétition de la migration sur copie de la base de prod (dry-run) | P0 | devops/fullstack | Backfill vérifié, temps mesuré, rollback documenté. |
| T-ME-26 | Déploiement Hostinger + vérif cron (clôture/grâce/email) post-migration | P0 | devops | Zéro action manuelle ; comptes existants fonctionnels. |
| T-ME-27 | Mise à jour doc : `cadrage.md` (Q14 caduque), CLAUDE.md (rôles/cloisonnement), guides orga | P0 | rédacteur | Doc = réel ; Q14 remplacée par la règle de cloisonnement. |

## 3. Jalons

| Jalon | Contenu | Condition de sortie |
|---|---|---|
| **J-ME-0 — Décisions bloquantes** | Q-ME-1 (nommage) + Q-ME-2 (SuperAdmin filiale_id) tranchées par le client | ✅ **Fait** — réponses consignées au `journal.md` (2026-07-25) |
| **J-ME-1 — Fondations & auth** | Lots A + B | Migration + scoping en place, cron/public non impactés (tests verts) |
| **J-ME-2 — Cloisonnement fonctionnel** | Lots C + D + F | Isolation démontrée bout-en-bout ; SuperAdmin transversal |
| **J-ME-3 — QR post-clôture** | Lot E | Démo : émargement à +14 min OK, +16 min refusé, email après grâce |
| **J-ME-4 — Gate sécurité** | Lot G | 0 finding critique/haut ; audit isolation passé |
| **J-ME-5 — Prod** | Lot H | Migration prod réussie, zéro régression sur l'existant |

## 4. Definition of Done (rappel, spécifique à l'évolution)

En plus de la DoD du socle (`plan-suivi.md` § 5) :

- **Aucune requête admin** ne renvoie de données d'une autre filiale (hors
  SuperAdmin) — **prouvé par un test d'accès croisé** pour chaque surface.
- Le **cron** (`CloseDueEvents`, `queue:work`) et la **page publique** traitent
  **toutes** les filiales sans être filtrés par le scope.
- La **migration** laisse zéro `filiale_id` NULL sur `events`/`event_types` et ne
  casse aucun compte existant.
- Le **QR post-clôture** respecte la borne exacte (test à ±1 s de la limite) et ne
  déclenche l'email récap qu'**après** la grâce.
- `journal.md` mis à jour ; `cadrage.md` §2/Q14 corrigé.

## 5. Jalons de sécurité intégrés (pour `security-expert`)

- **Conception (fin Lot B)** : revue du choix de scoping (global scope vs policies)
  et de son **non-application** au cron/public — c'est le risque n°1 (RME-1/RME-2).
- **Avant Lot H** : audit complet d'isolation inter-filiales + escalade de rôle
  (`Organisateur`→`AdminFiliale`→`SuperAdmin`), revue des URLs d'export.
- **Règle maison** : aucun agent ne valide sa propre isolation ; l'audit est
  indépendant du développement.

## 6. Prochaine étape recommandée

1. Lancer **`ux-designer`** sur : gestion des filiales (SuperAdmin), sélecteur
   de filiale éventuel (Q-ME-6), paramétrage par filiale, message visiteur de
   grâce (Q-ME-11), écrans de création de comptes scopés.
2. Puis **`senior-fullstack`** : arbitrer Q-ME-3 (scoping), implémenter Lots A→F
   selon le séquencement, avec le Lot E lançable en parallèle.
