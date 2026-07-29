# Test de recrutement en ligne — design

Date : 2026-07-29
Statut : approuvé par l'utilisateur (cadrage), en attente de relecture spec

## 1. Contexte et périmètre

ACS Groupe veut faire passer des tests de recrutement via Presence, à des candidats
externes non authentifiés. Première phase : poste **Fiscaliste Groupe**.

Documentation source fournie (`/Users/ferie/Downloads/Fiscaliste/`) :
- `01_Sujet_Candidat_Fiscaliste_Groupe.docx` — épreuve complète : 3h écrit + 15 min de
  soutenance orale, cas fiscal KÔRÔ HOLDING, 6 parties (A à F, 100 points), conçue pour
  passation en salle surveillée (téléphones/IA/Internet interdits).
- `03_Annexes_Candidat_Fiscaliste_Groupe.xlsx` — classeur à compléter (onglets
  Transactions, Échéances, Contrats, Analyse candidat).
- `04_Classeur_Maitre_Confidentiel.xlsx` — corrigé. **Seule la Partie B (onglet
  Transactions, 18 lignes, 25 points) a une clé de correction chiffrée exploitable** :
  colonnes `Ajustement TVA indicatif`, `Retenue attendue`, `Classement`. Les parties
  A/C/D/E/F (75 points) sont des rédactions libres/tableaux d'analyse qualitative,
  non auto-corrigeables sans jugement humain.

**Décision de cadrage** : le format original (salle surveillée, soutenance orale,
rendu Excel/Word) est incompatible avec un candidat distant non loggé et une
correction automatique. La V1 adapte le test à un format web court et
entièrement auto-corrigé, en ne reprenant que la Partie B (18 transactions).
Les parties rédactionnelles ne font pas partie de cette V1.

Décisions validées avec l'utilisateur :
- Accès candidat : URL publique par poste (pas d'invitation nominative).
- Durée : 45 minutes, chronométrées côté serveur.
- Un seul essai par email et par poste.
- Reprise possible via le lien personnel tant que le temps réel n'est pas écoulé
  (fermeture de navigateur ≠ perte du travail).
- Candidat ne voit jamais son score — seul l'admin y a accès.
- Recrutement cloisonné par filiale (même principe que les événements).
- Modèle de données générique (réutilisable pour de futurs postes), mais **pas**
  d'interface admin de création de test en V1 : un nouveau poste s'ajoute par
  seeder, comme pour Fiscaliste. L'admin gère uniquement candidats/résultats.

## 2. Flux fonctionnel

### Côté candidat (public, `/recrutement/*`)

1. `GET /recrutement/{slug}` — page du poste (titre, instructions courtes, durée
   annoncée) + formulaire d'entrée (nom, prénom, email, téléphone optionnel).
2. `POST /recrutement/{slug}` — crée une `RecruitmentCandidature` :
   - Si un email a déjà une candidature pour ce test (peu importe le statut) →
     rejet avec message clair (« vous avez déjà passé ce test »). Idempotent :
     ne recrée jamais de deuxième ligne pour le même couple (test, email).
   - Sinon : crée la candidature, `started_at = now()`, `expires_at = started_at +
     duree_minutes`, génère un `token` opaque (aléatoire, 32+ octets, usage
     interne uniquement — jamais affiché, sert juste de clé d'URL).
   - Redirige vers `GET /recrutement/{slug}/test/{token}`.
3. `GET /recrutement/{slug}/test/{token}` — sert la page de test si la
   candidature existe, n'est pas déjà soumise, et `now() < expires_at`. Sinon :
   page « session expirée » ou « déjà soumis », sans détail de score.
   Affiche les 18 transactions (contexte : entité, date, pièce, tiers, nature,
   montant HT, TVA comptabilisée, retenue opérée, informations disponibles) avec
   3 champs par transaction : classement (select), ajustement TVA (nombre),
   retenue attendue (nombre). Réponses déjà sauvegardées pré-remplies. Chrono
   JS calculé depuis `expires_at` (purement indicatif — l'autorité est serveur).
4. Autosave : chaque champ modifié déclenche un `PATCH` (fetch JSON, sur le
   modèle de `PublicAttendanceController`) qui crée ou met à jour la
   `RecruitmentReponse` correspondante. Rejette avec 410 si la candidature est
   déjà soumise ou expirée côté serveur (le client recharge alors la page).
5. `POST /recrutement/{slug}/test/{token}/soumettre` — finalise : calcule le
   score, `submitted_at = now()`, `statut = soumis`. Redirige vers une page de
   confirmation neutre (« vos réponses ont bien été transmises »). Notifie
   l'admin par email (queue existante, `ShouldQueue`).
6. Commande planifiée `recruitment:close-expired` (même mécanique que
   `events:close-due`, appelée depuis `routes/console.php`, idempotente) :
   pour toute candidature `statut = en_cours` et `expires_at < now()`, calcule
   le score sur les réponses déjà enregistrées et passe `statut = expire`
   (distinct de `soumis` pour l'admin, même logique de notation).

### Côté admin (`/admin/recrutement/*`, cloisonné filiale)

- Liste des postes de recrutement (scopée filiale via `FilialeScope`), avec
  toggle actif/inactif (un poste inactif renvoie 404 sur son URL publique).
- Liste des candidatures d'un poste : nom, email, statut (en_cours/soumis/
  expiré), score (X/54), date de soumission.
- Détail d'une candidature : tableau réponse candidat vs réponse attendue,
  question par question, avec indicateur correct/incorrect par champ.

## 3. Notation

- Chaque transaction vaut 3 points possibles (classement, ajustement TVA,
  retenue attendue), comparés indépendamment à `reponse_attendue` (JSON stocké
  sur la question, jamais exposé publiquement).
- Comparaison numérique : `abs(saisi - attendu) <= tolerance` (tolérance stockée
  par question, défaut 0 FCFA).
- Comparaison classement : égalité stricte de chaîne (`Conforme|Modéré|Majeur|
  Critique`).
- Score final = nombre de champs corrects / 54, affiché à l'admin en fraction
  et en pourcentage. Pas de remappage sur le barème /100 d'origine (n'aurait
  plus de sens : 75 des 100 points portaient sur les parties retirées de la V1).

## 4. Modèle de données

```
recruitment_tests
  id, filiale_id (FK filiales), titre, slug (unique), duree_minutes,
  instructions (text), actif (bool), timestamps

recruitment_questions
  id, recruitment_test_id (FK), ordre (int),
  contexte (json)            -- champs affichés au candidat (entité, pièce, tiers, montant HT, TVA comptabilisée, retenue opérée, infos disponibles)
  reponse_attendue (json)    -- {classement, ajustement_tva, retenue_attendue} — jamais exposé côté public
  tolerance (int, défaut 0)
  timestamps

recruitment_candidatures
  id, recruitment_test_id (FK), nom, prenom, email, telephone (nullable),
  token (string, unique, index), started_at, expires_at, submitted_at (nullable),
  statut (enum: en_cours|soumis|expire), score (int, nullable),
  timestamps
  UNIQUE(recruitment_test_id, email)

recruitment_reponses
  id, recruitment_candidature_id (FK), recruitment_question_id (FK),
  classement_saisi (nullable), ajustement_tva_saisi (nullable),
  retenue_saisie (nullable), timestamps
  UNIQUE(recruitment_candidature_id, recruitment_question_id)
```

`recruitment_tests` porte `filiale_id` et adopte le même `FilialeScope` global
scope que `EventType`/`Setting` (cf. `mem:architecture` Serena). Les tables
`recruitment_candidatures`/`recruitment_reponses` ne portent pas `filiale_id` :
elles héritent du cloisonnement via `recruitment_test_id` (une requête admin
scope toujours via le test parent, jamais en accès direct à la candidature par
ID brut).

Enum PHP natif `RecruitmentCandidatureStatus` (`en_cours|soumis|expire`), avec
`label()` FR comme les autres enums du projet (convention `mem:conventions`).

## 5. Sécurité et anti-triche

- Chrono faisant foi côté serveur uniquement (`expires_at`), jamais confiance
  au client. Toute écriture (autosave, soumission) vérifie `now() < expires_at`
  et `statut = en_cours` avant d'accepter.
- `token` de candidature : aléatoire non devinable, généré une seule fois,
  jamais régénéré. Sert uniquement de clé d'URL — pas un identifiant de
  session, pas de cookie nécessaire.
- Un seul essai par email et par poste (contrainte unique en base, vérifiée
  aussi applicativement pour message d'erreur clair).
- Hors périmètre V1 (limite connue, documentée) : détection de changement
  d'onglet, copier-coller, plusieurs écrans — techniquement peu fiable côté
  navigateur et facilement contournable, donc pas d'investissement V1.
- `reponse_attendue` (corrigé) ne transite jamais vers le rendu candidat : le
  contrôleur public ne charge que `contexte`, jamais `reponse_attendue`.

## 6. Interface

- Nouvel item de navigation admin « Recrutement », visible pour
  `AdminFiliale`/`Organisateur`/`SuperAdmin` (pas `Agent`).
- Pages candidat en assets manuels (`public/css`/`public/js`, `versioned_asset()`)
  comme les autres écrans publics (`event-detail.js` etc.), pas de composant Vite.
- Suit les conventions existantes : `declare(strict_types=1)`, logique dans
  `App\Services\RecruitmentService` (démarrage candidature, autosave, calcul de
  score, finalisation), contrôleurs fins, validation dans
  `App\Http\Requests\Recruitment\*`.

## 7. Tests

Feature (PHPUnit, SQLite `:memory:`) :
- Parcours complet : accès page poste → démarrage → autosave → soumission →
  score calculé correct vis-à-vis du corrigé seedé.
- Doublon email sur le même poste → rejeté, pas de deuxième candidature créée.
- Autosave/soumission refusés après expiration ou après soumission déjà faite.
- Commande `recruitment:close-expired` : clôture et note une candidature
  expirée non soumise, idempotente (deuxième exécution sans effet).
- Cloisonnement filiale admin : un `AdminFiliale` d'une filiale ne voit ni le
  poste ni les candidatures d'une autre filiale (404/absence de la liste).
- Poste inactif → 404 sur les routes publiques.

## 8. Hors périmètre (explicitement exclu de cette V1)

- Parties A/C/D/E/F du sujet original (rédactionnelles, non auto-corrigeables).
- Soutenance orale.
- Interface admin de création/édition de test (ajout de poste = seeder dev).
- Détection anti-triche avancée (onglets, copier-coller, webcam, etc.).
- Affichage du score au candidat.
- Relance/prolongation d'une candidature par l'admin.

## 9. Seeder du contenu Fiscaliste

Les 18 transactions et leur corrigé (source : `04_Classeur_Maitre_Confidentiel.xlsx`,
onglet `Corrigé CONFIDENTIEL`, croisé avec l'onglet `Transactions` du classeur
candidat pour le contexte affiché) sont portées dans un seeder dédié
(`RecruitmentFiscalisteSeeder` ou équivalent factory), pas committées en clair
dans une migration — le corrigé reste un contenu métier sensible, traité comme
donnée de seed plutôt que structure de schéma.
