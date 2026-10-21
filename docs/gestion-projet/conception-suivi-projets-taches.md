# Conception — Suivi de projets & tâches (Pulse fondu dans Presence)

| | |
|---|---|
| **Statut** | Conception validée en brainstorming — 27 juillet 2026 |
| **Décision structurante** | Pulse n'est PAS une application séparée : c'est une **nouvelle fonctionnalité de Presence** |
| **Sources de vérité amont** | `pulse/docs/specification-technique-v2.md` (Partie B = pilote, fait foi) ; `SUIVI PROJET ACS GROUP.xlsx` (fichier réel profilé le 22/07/2026) |
| **Périmètre** | Pilote Access Security : 6 agents + PDG, une entité active, schéma multi-filiales déjà présent dans Presence |

---

## 1. Décision et justification

Le suivi de projet ACS (aujourd'hui un Google Sheets à deux onglets, mort de données périmées) est repris dans une application structurée. Le produit avait été cadré sous le nom **Pulse** sur une stack séparée (NestJS + PostgreSQL). **Décision du 27/07/2026 : fondre Pulse dans Presence** comme fonctionnalité « Suivi de projets & tâches », plutôt que maintenir deux applications distinctes.

**Pourquoi fondre :**

- Presence est **livré, fonctionnel, en production** (Hostinger) et embarque déjà les briques dont Pulse a besoin.
- Une seule application = un seul déploiement, une seule base, une seule authentification. **La couche d'intégration inter-applications (Presence↔Pulse) disparaît entièrement.**
- Presence repose déjà sur les **mêmes principes** que la spec Pulse (statut dérivé jamais stocké, cron sans worker persistant, cloisonnement par entité imposé au niveau de la donnée, journal d'audit inaltérable, enums FR).

**Ce qu'on perd (assumé, faible) :** le squelette applicatif Pulse déjà démarré (NestJS + Postgres, 2 commits) est abandonné. La **spécification** Pulse, elle, reste réutilisée à 100 % — c'est le plan métier, pas le code.

**Condition de réversibilité :** si la holding exige un jour un système indépendant de Presence pour toutes les entités, le modèle de données ci-dessous est conçu propre et cloisonné par filiale, donc extractible. On ne se ferme pas cette porte.

---

## 2. Ce que Presence fournit déjà (réutilisé, non reconstruit)

| Besoin issu de la spec Pulse | Brique Presence existante |
|---|---|
| Cloisonnement par entité (`entity_id`/`organization_id` sur toutes les tables racines, aucune requête sans filtre) | **`Filiale` + `FilialeScope` (global scope) + `ApplyFilialeScope`** — déjà testé (`FilialeIsolationHardeningTest`) |
| Statut / avancement dérivés, jamais stockés | **`Event::status()`** applique déjà ce principe |
| Relances planifiées sans worker persistant | **`schedule:run` (cron unique) + file `database`** déjà en place |
| Emails transactionnels asynchrones | file d'emails Presence (`ShouldQueue`, `QUEUE_CONNECTION=database`) |
| Journal d'audit append-only | **table `audit_logs` + `AuditLog::record()`** déjà présente (aujourd'hui sous-exploitée — cf. audit finding LOG-01) |
| Enums PHP natifs avec `label()` FR | convention Presence (`EventStatus`, `UserRole`…) |
| Import de fichier Excel | **`maatwebsite/excel`** déjà installé (migration des 31 lignes) |
| Comptes, rôles, sessions, sécurité | `User`, `EnsureUserRole`, `EnsureActiveSession`, `SecurityHeaders` |

**Conséquence :** la fonctionnalité est majoritairement du **modèle métier + écrans**, pas de l'infrastructure à réinventer.

---

## 3. Nouveau pan — Annuaire des agents par filiale

Ajout demandé le 27/07/2026, absent jusqu'ici. Presence distingue aujourd'hui :

- **Personnes** (`Person`) : visiteurs qui émargent, référentiel par email normalisé, **partagé sur toute la holding**, non rangé par filiale.
- **Comptes** (`User`) : ceux qui se connectent (Admin/Organisateur), rangés par filiale.

Il manque un **annuaire des agents (collaborateurs internes) rangé par filiale**, distinct des visiteurs.

### 3.1 Modèle retenu — l'agent est un compte `User` enrichi

Décision : **tous les agents ont un compte** (pour voir leurs tâches assignées et soumettre leurs livrables). On n'introduit donc **pas** de modèle séparé — on **enrichit `User`** :

- Nouveaux champs sur `users` : `phone NULL`, `direction NULL`, `service NULL`, `position NULL`, `is_active` (déjà couvert par le mécanisme de désactivation existant), `person_id FK NULL` (lien optionnel vers `Person` si l'agent émarge aussi comme participant).
- `users.filiale_id` **existe déjà** → le rangement par filiale est immédiat.
- Nouveau rôle **`Agent`** dans `App\Enums\UserRole` (les rôles réels existants sont `SuperAdmin` | `AdminFiliale` | `Organisateur` ; on ajoute `Agent` = exécutant de tâches, sans pouvoir événement ni accès Paramètres — moindre privilège ; voir §7).
- **Annuaire par filiale** : écran listant les agents d'une filiale (nom, poste, direction, service, téléphone, statut). Chaque filiale voit ses agents ; Admin/PDG voit tout (scope via `FilialeScope`).

### 3.2 Bénéfices

- **Corrige le finding d'audit RGPD-03** : l'annuaire du personnel n'est plus visible à plat sur toute la holding — il est cloisonné par filiale.
- Fournit à Pulse son **vivier de personnes assignables** (les agents d'une filiale).
- Pas de double saisie : l'annuaire d'une filiale = la liste de ses comptes agents.

### 3.3 Point d'attention

« Tout le monde a un compte » augmente la surface d'authentification (chaque compte est une porte d'entrée). Justifié pour un outil interne où chacun doit consulter ses tâches. Mitigations : politique de mot de passe renforcée (cf. audit AC-02 → `Password::min(12)->uncompromised()`), désactivation à la sortie d'un collaborateur (déclenche la réassignation de ses tâches).

---

## 4. Modèle de données — Suivi de projets & tâches

Porté de la Partie B de la spec Pulse vers Laravel/MySQL. Conventions Presence : `declare(strict_types=1)`, services `final`, migrations versionnées, enums natifs avec `label()`, suppression logique (`deleted_at`) sur les données métier.

### 4.1 Structure projet

- **`projects`** — `filiale_id FK`, `name`, `client_name NULL`, `type` (`client` | `interne`), `status` (`active` | `paused` | `completed` | `cancelled`), `start_date`, `end_date_planned`, `end_date_baseline` (figée à la validation, non modifiable par l'UI), `deleted_at`.
  - Projets typés d'après le fichier réel : `client` (DGPSP, OIC, LONACI, DGF, DILITRUST) / `interne` (Office ACS). Le pack de revue les sépare.
- **`phases`** — `project_id FK`, `name`, `order_index INT`. (Cadrage → Conception → Dev P1/P2/P3 → Déploiement, adaptable par projet.)

### 4.2 Tâches

- **`tasks`**
  - `phase_id FK`, `title`, `description`, `status` (enum, voir §5), `priority` (`low`|`medium`|`high`|`critical`).
  - `assigned_to FK users NULL` (le responsable unique).
  - `assigned_by FK users` — **l'émetteur qui confie la tâche et qui la validera** (règle de validation généralisée, §6).
  - `estimated_hours decimal NULL` (NULL = non estimée, exclue du calcul de charge), `actual_hours decimal NULL`.
  - `date_start_planned`, `date_end_planned` (CHECK `≥ date_start_planned`), `date_end_baseline` (copiée de `date_end_planned` au passage en `assigned`, **jamais modifiée ensuite**), `date_start_actual NULL`, `date_end_actual NULL` (posée au passage en `done`).
  - `deliverable_url NULL` — **obligatoire au passage en « Soumis »** ; l'API/contrôleur refuse la transition sans lien.
  - `last_activity_at` — alimente la détection de péremption (§8).
  - `origin` (`manual` | `review`) + `business_review_id FK NULL` + `proposed_by NULL` — **crochets Greffier posés dès maintenant** (§9).
  - `replan_count INT default 0` — compteur de replanifications.
  - **Pas de colonne `percent_complete`** : toujours calculé à la lecture (comme `Event::status()`).
- **`task_events`** (audit append-only par tâche) — `task_id FK`, `actor_id FK users NULL`, `event_type` (`created`, `status_changed`, `assigned`, `reassigned`, `replanned`, `estimate_changed`, `submitted`, `resolved`, `returned`, `staleness_flagged`…), `payload JSON` (avant/après), `created_at`. Source des écarts et de la traçabilité. **Complémentaire du `audit_logs` global de Presence** (qui trace, lui, les actions transverses).
- **`task_requests`** (demandes complémentaires) — `task_id FK`, `author_id FK`, `type` (`clarification`|`documentation`|`resource`|`deadline`|`issue`), `message`, `status` (`open`|`answered`|`closed`), `response NULL`, `responded_by NULL`, `responded_at NULL`.

### 4.3 Business review

- **`business_reviews`** — `filiale_id FK`, `period_start`, `period_end` (fenêtre 10 jours), `held_at NULL`, `status` (`scheduled`|`held`|`skipped`), `snapshot JSON` (**pack figé à la tenue, jamais recalculé**).
- **`review_recordings`** (crochet Greffier v1.1, table posée vide en v1) — `business_review_id FK`, `audio_ref`, `transcript_ref`, `status`.

### 4.4 Colonnes dormantes (moteur d'assignation, reporté au déploiement holding)

Le moteur de proposition d'assignation n'est **pas** construit au pilote, mais ses crochets sont posés pour éviter une migration coûteuse plus tard : `estimated_hours`, `assigned_to`, `origin`/`proposed_by` (déjà ci-dessus), plus une table `assignment_policies` **créée mais non lue**. Aucun code d'appel au pilote (ce n'est pas un feature flag, c'est du code présent jamais invoqué).

---

## 5. Machine à états des tâches

```
not_started ──▶ assigned ──▶ in_progress ◀──▶ on_hold
                                 │
                                 │ (soumission agent, deliverable_url obligatoire)
                                 ▼
                             to_validate ──(coche émetteur)──▶ done
                                 │
                                 │ (renvoi émetteur, motif obligatoire)
                                 ▼
                             in_progress
   cancelled ◀── (depuis tout état sauf done)
```

Enum `App\Enums\TaskStatus` (codes neutres + `label()` FR). Vocabulaire **affiché** de l'équipe :

| Code interne | Affiché | Geste |
|---|---|---|
| `to_validate` | **Soumis** | l'assigné clique « Soumettre » (lien du livrable obligatoire) |
| `done` | **Résolu** | l'émetteur (N+1) coche « Résolu » — ou renvoie avec motif |

Règles (contrôlées côté serveur, jamais seulement UI) :

- Transition hors graphe → refusée (HTTP 409 + transition attendue).
- `in_progress → to_validate` : réservé à l'assigné, `deliverable_url` obligatoire.
- `to_validate → done` : **réservé à l'émetteur** (`assigned_by`) ou Admin. **Jamais d'auto-validation.**
- `to_validate → in_progress` (renvoi) : émetteur uniquement, motif obligatoire, loggé.
- Passage en `assigned` : copie `date_end_planned → date_end_baseline` (une seule fois).
- Une tâche `done` (« Résolu ») **sort des vues actives** ; elle reste dans l'historique, les KPIs et le pack de revue.

---

## 6. Règles métier clés (reprises de la Partie B)

- **Validation par l'émetteur** : le valideur d'une livraison est celui qui a confié la tâche (`assigned_by`), le N+1 fonctionnel. Le PDG valide ce qu'il confie à la direction et à la PMO ; la direction et la PMO valident ce qu'elles confient aux agents.
- **Flux continu d'assignation** : les tâches se confient à tout moment, pas seulement en revue.
- **Baseline figée** : replanifier ne réduit jamais l'écart affiché ; `replan_count` incrémenté et `task_events.replanned` loggé.
- **Une tâche = un responsable unique** ; co-intervenants = participants (les cellules multi-noms du Sheet sont éclatées à la migration).
- **Livrable obligatoire** : 26/31 tâches réelles référençaient déjà un fichier — la règle formalise une pratique existante.

---

## 7. Rôles et permissions

Rôles réels de Presence : **`SuperAdmin` | `AdminFiliale` | `Organisateur`** (le `admin` de l'ancienne doc est obsolète, déjà migré). On ajoute **un seul** nouveau rôle `Agent`. Correspondance spec Pulse → rôles Presence :

| Poste | Rôle Presence | Confier des tâches | Valider | Visibilité |
|---|---|---|---|---|
| **PDG holding** | `SuperAdmin` (`filiale_id = NULL`, au-dessus des filiales) | vers la direction et la PMO | ce qu'il a confié | toute la holding |
| **Direction d'entité (DG)** | `AdminFiliale` | vers tous | ce qu'elle a confié | toute l'entité |
| **PMO** | `AdminFiliale` | vers tous | ce qu'elle a confié | toute l'entité |
| **Agents** | `Agent` (nouveau) | pour eux-mêmes | ❌ | leurs tâches et projets |

Le nouveau rôle `Agent` = moindre privilège : voit et exécute ses tâches, ne gère ni événements ni Paramètres. « Qui valide » ne dépend pas du rôle mais du champ `assigned_by` (l'émetteur de la tâche).

> **À imposer dans l'épic qui rend `Agent` créable** (provisioning de comptes + UI des tâches) : au pilote actuel `Agent` n'est **pas** créable via l'app (`AccountController::ASSIGNABLE_ROLES` ne liste que `AdminFiliale`/`Organisateur`), donc aucun compte Agent ne peut se connecter et l'absence de garde de rôle sur les routes événement/présence est **latente**. Dès que le provisioning d'un compte `Agent` sera ouvert, il faudra **dans le même lot** garder les surfaces opérationnelles (événements, participants, statistiques, portfolio, rapports) contre le rôle `Agent` (ex. `role:organisateur,admin_filiale,super_admin`) et rediriger la page d'accueil d'un Agent vers l'annuaire/ses tâches — sinon l'engagement « moindre privilège » ci-dessus n'est pas tenu. (Finding de revue finale Épic 1, parké car latent.)

Contrôles côté serveur systématiques ; **tests des interdits obligatoires** dès la première story : auto-validation refusée, soumission sans livrable refusée, transition hors machine à états → 409, écriture hors rôle → 403. (Presence a déjà `SecurityGateAuditTest` / `GateIntegrationCrossAgentTest` comme modèles.)

---

## 8. Anti-péremption (le vrai problème du Sheet) — via le cron existant

Le Sheet est mort de données non tenues à jour (30/31 « Réalisé », **zéro « En cours »** = journal rempli après coup). Mécanismes de relance, branchés sur `schedule:run` (nouvelle commande `tasks:run-reminders`, pattern identique à `events:close-due`) :

| Déclencheur | Action | Canal |
|---|---|---|
| Tâche `in_progress` muette > 7 jours ouvrés | relance à l'assigné | email + in-app |
| Échéance à J-2, statut ≠ `done` | relance assigné + visible émetteur | email |
| Échéance dépassée | drapeau `overdue` (dérivé) + digest quotidien à l'émetteur | digest |
| « Soumis » sans coche > 48 h | **rappel au N+1** (exigence explicite du PDG) | notification |
| Demande complémentaire `open` > 48 h | relance à l'émetteur | notification |
| Pack de revue prêt à J-1 | notification | email |

Fenêtre d'envoi 08 h–19 h du fuseau du destinataire. Réponse en 1 clic depuis l'email (« toujours en cours » / « terminé » / « bloqué » / « replanifier ») — mettre à jour doit coûter 5 secondes.

---

## 9. Business review décadaire + écran PDG

- **Cycle de 10 jours** = métronome du système. Chaque filiale tient sa revue.
- **Pack auto-généré (< 1 min)** depuis `task_events` + `tasks` : (1) décisions de la revue précédente tenues/non tenues, (2) livré sur la période avec liens cliquables, (3) retards vs baseline + replanifications, (4) demandes complémentaires ouvertes, (5) KPIs de la période, (6) mode présentation projetable.
- **Décisions → tâches** : chaque décision de séance crée une tâche (`origin=review`, `business_review_id`) ; la revue suivante vérifie son état automatiquement.
- **`snapshot JSON` figé** à la tenue : ce qui a été présenté reste ce qui a été présenté.
- **Écran PDG « ce qui est / ce qui n'est pas »** : consultation sans formation (critère de succès B8-6).

**Le Greffier (capture audio → transcription → tâches candidates)** : **v1.1, après 3 cycles de revue manuels**. Crochets posés en v1 (`origin`, `proposed_by`, table `review_recordings`). Chaîne cible : audio → transcription → extraction structurée → écran de validation en séance (accepter/corriger/rejeter). Découplé : panne = revue manuelle, rien ne casse. Consentement à l'enregistrement obligatoire.

---

## 10. Reproduction du fichier Excel (traçabilité de bout en bout)

- **Onglet « Tableau de SUIVI DES TÂCHES » (journal chrono)** → **vue liste des tâches** : `date · projet · phase · tâche · responsable · état · livrable · commentaire`. Filtrable (filiale/projet/personne/priorité/overdue), **exportable au même format** (via `maatwebsite/excel`).
- **Onglet « SUIVI GLOBAL DE PROJET »** → **vue projet par phases** : état, priorité, dates initiales vs réelles, livrable, **% avancement calculé** (jamais saisi), écart vs baseline. En mieux : plus de formules cassées (l'écart de −46 210 j du template hérité disparaît).

Objectif « tout retracer comme celui-ci » = atteint et vivant : qui a fait quoi, quand, pour quel projet/phase, avec quel livrable et quel écart.

---

## 11. Migration des données réelles

- Reprise du **seul onglet journal** (31 lignes, 29/06 → 16/07, 8 projets, ~5 personnes). L'onglet « suivi global » (template 2018, colonnes incohérentes, formules cassées) est **archivé sans migration**.
- **Référentiel personnes avec alias** : 12 identités pour ~5 personnes (noms, emails, cellules multi-noms) → réconciliation vers les comptes agents, table d'alias.
- Import **idempotent et rejouable**, recette métier sur l'import complet, **zéro perte silencieuse** : toute ligne importée OU listée en anomalie. Tâches historiques importées sans baseline (marquées `migrated`, exclues des indicateurs d'écart).
- Dates ambiguës (`03/07/2026`) interprétées en locale FR (jour/mois) ; incohérences (fin < début) listées, jamais corrigées en silence.

---

## 12. Contraintes Hostinger — toutes respectées

- **Pas de worker/queue persistant** : la spec Pulse pilote l'imposait déjà (« relances par cron interne ») → **aligné nativement** avec le modèle Presence.
- Notifications via la file `database` existante, vidée par le `queue:work --stop-when-empty` déjà planifié.
- Aucune dépendance à Redis/BullMQ (écartés : ils étaient prévus pour le moteur d'assignation, reporté).
- Statuts/avancements calculés à la lecture → pas de colonnes dérivées à maintenir.

---

## 13. Divergences assumées vs spécification Pulse

| Point spec Pulse | Choix dans Presence | Raison |
|---|---|---|
| PostgreSQL 16 (`JSONB`, RLS) | MySQL + colonnes `JSON` | Presence est sur MySQL/Hostinger ; JSON MySQL suffit pour `snapshot`/`payload` au pilote |
| Auth Google OIDC | Auth Presence existante (mot de passe local) | 7 comptes au pilote ; OIDC reporté (surface réduite d'abord) |
| Table `i18n_labels` multilingue | Enums natifs avec `label()` FR | Pilote monolingue FR ; convention Presence ; i18n différé |
| Cloisonnement par `organization_id` | Cloisonnement par `filiale_id` (`FilialeScope`) | Déjà présent et testé dans Presence |
| Stack NestJS/TypeScript | Laravel/PHP | Fondu dans l'existant |

---

## 14. Plan par étapes (ordre imposé par le PDG)

1. **Annuaire des agents par filiale** (enrichissement `User` + rôle `agent` + écran annuaire) — socle du vivier assignable, corrige RGPD-03.
2. **Projets / phases / tâches + machine à états + `task_events`** — suivi manuel complet, déjà supérieur au Sheet.
3. **Soumission / résolution** (livrable obligatoire + validation par l'émetteur) + **demandes complémentaires**.
4. **Business review décadaire** (pack + snapshot + décisions → tâches).
5. **Notifications & rappels** (transactionnelles dès l'étape 3 ; rappels cron) — **avant** l'écran PDG (exigence du PDG).
6. **Écran PDG « ce qui est / ce qui n'est pas »** + vues transversales + export Excel.

Migration des 31 lignes en semaine 1 : on développe sur données réelles. Dogfooding : le backlog de la fonctionnalité est géré dans l'outil dès que possible.

---

## 15. Critères d'acceptation (repris de B8, mesurables)

1. 3 cycles de revue complets (30 jours) tenus dans l'outil avant toute démo au PDG.
2. Données 100 % à jour à chaque revue : zéro tâche `in_progress` muette > 7 j ouvrés au moment de la séance.
3. Pack de revue généré en < 1 minute, sans préparation manuelle.
4. 100 % des tâches closes ont un livrable lié et une validation par leur émetteur.
5. Chaque décision de revue est retrouvable en tâche trackée à la revue suivante.
6. Le PDG consulte « ce qui est / ce qui n'est pas » sans assistance ni formation.
7. Déclarer une tâche terminée sans `deliverable_url` refusé ; auto-validation (`to_validate → done` par l'assigné) refusée — tests automatisés.
8. Annuaire des agents cloisonné par filiale : un agent ne voit pas l'annuaire d'une autre filiale (test de scope).
9. Import de migration : 100 % des lignes soit importées, soit listées en anomalie — zéro perte silencieuse.

---

## 16. Prochaine étape

Passage à la planification d'implémentation (découpage en stories exécutables par epic), une fois ce document relu et validé.
