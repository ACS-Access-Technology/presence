# Méthodologie — projet Presence

- **Statut** : recommandation initiale (à valider)
- **Date** : 2026-07-19
- **Rédacteur** : agent `chef-de-projet`

## 1. Contexte qui conditionne le choix

- Projet **solo**, piloté par un utilisateur unique s'appuyant sur des **agents
  IA** spécialisés (dev, sécurité, QA, devops, doc).
- **Fort niveau d'incertitude produit** au départ (plusieurs questions bloquantes
  ouvertes : échelle, authentification, valeur probante, multi-tenant).
- Besoin de **livraisons fréquentes et visibles** (un MVP démontrable rapidement).
- Pas de contrainte d'équipe (pas de coordination inter-humains, pas de vélocité
  d'équipe à gérer, pas de cérémonies lourdes justifiées).

## 2. Comparatif des méthodologies

| Méthodo | Principe | Adaptée ici ? | Pourquoi / pourquoi pas |
|---|---|---|---|
| **Scrum (complet)** | Sprints fixes, rôles (PO/SM/Dev), cérémonies (planning, daily, review, rétro), vélocité | ❌ | Cérémonial trop lourd pour un pilote solo ; rôles Scrum inutiles sans équipe. Sur-process. |
| **Waterfall** | Phases séquentielles figées (specs → dev → test → livraison) | ❌ | Incompatible avec la forte incertitude produit ; on doit apprendre en livrant, pas figer un cahier des charges complet en amont. |
| **Kanban** | Flux continu, WIP limité, board `À faire / En cours / Revue / Fait`, pas de sprint imposé | ✅ (base) | Idéal pour un flux piloté par la priorité, faible surcharge de process, cadence naturelle. Parfait pour un solo. |
| **Scrumban (hybride)** | Kanban + jalons/itérations légères pour cadencer et jalonner | ✅✅ (recommandé) | Garde la légèreté du Kanban mais ajoute des **itérations courtes jalonnées** (MVP, V1…) et des points de synchro (dont les jalons sécurité). |
| **Lean / XP** | Réduction du gaspillage / pratiques d'ingénierie (TDD, revue, intégration continue) | ✅ (à emprunter) | Pas une méthodo de pilotage à part entière ici, mais on **emprunte les pratiques d'ingénierie** (tests des chemins critiques, revue, CI) via les agents dev/QA/devops. |

## 3. Recommandation retenue : **Scrumban léger**

**Un tableau Kanban** priorisé (le backlog de `plan-suivi.md`), **rythmé par des
itérations courtes jalonnées** (Itération 0 « fondations », MVP, V1…), et enrichi
des **pratiques d'ingénierie Lean/XP** portées par les agents.

Pourquoi ce choix :
- **Légèreté** adaptée à un pilotage solo assisté par agents — zéro cérémonie
  inutile, mais un cadre clair et des jalons.
- **Absorbe l'incertitude** : on livre par incréments, on ré-priorise le board à
  chaque itération selon les réponses de l'utilisateur.
- **Rend le progrès visible** : colonnes d'état + jalons datés.
- **Intègre la sécurité et la qualité comme des étapes du flux**, pas comme une
  arrière-pensée (colonnes/gates dédiées).

Pourquoi pas les autres : Scrum et Waterfall sont écartés (respectivement trop
cérémoniels et trop rigides) ; Kanban pur est la base mais manque de jalons pour
cadencer un MVP → Scrumban comble ce manque.

## 4. Fonctionnement concret

### Tableau Kanban (colonnes)
`Backlog` → `Prêt (spécifié)` → `En cours` → `Revue (code + QA)` →
`Gate sécurité` (pour tout item sensible/avant déploiement) → `Fait`.

- **Limite de WIP** : 1 à 2 items « En cours » à la fois (pilotage solo).
- **Definition of Ready** : un item passe en `Prêt` quand son objectif, ses
  critères d'acceptation et ses éventuelles contraintes sécurité/RGPD sont écrits.
- **Definition of Done** : voir `plan-suivi.md` (§ DoD).

### Itérations
- Itérations courtes (≈ 1 semaine, ajustable) jalonnées par livrable : chaque
  itération vise un incrément démontrable.
- **Pas de daily** (solo) ; à la place, le **`journal.md` est le rituel** : mis à
  jour à chaque décision/avancement/blocage.
- **Revue de fin d'itération** : démonstration de l'incrément + mise à jour du
  board et des risques.
- **Rétro légère** : 3 lignes en fin d'itération dans le journal (ce qui a marché,
  ce qui a bloqué, ce qu'on change).

### Cérémonies (version allégée)
| Rituel | Fréquence | Support |
|---|---|---|
| Mise à jour du flux | En continu | `journal.md` + board |
| Revue d'incrément | Fin d'itération | Démo + `journal.md` |
| Rétro express | Fin d'itération | 3 lignes dans `journal.md` |
| Gate sécurité | Avant tout déploiement + après dev sensible | Audit `security-expert` |

## 5. Rôles (portés par l'utilisateur + agents)

- **Product owner / pilote** : l'utilisateur (priorise, tranche les questions).
- **Chef de projet** : agent `chef-de-projet` (cadre, planifie, tient le journal).
- **UX** : agent `ux-designer` (parcours participant/organisateur, design system).
- **Développement** : agent `senior-fullstack`.
- **QA** : agent `qa-testeur`.
- **Sécurité** : agent `security-expert` (gate obligatoire avant prod).
- **Déploiement / SRE** : agent `devops-sre`.
- **Documentation** : agent `redacteur-technique`.

## 6. Indicateurs de suivi (légers)

- Avancement par itération (items faits / prévus).
- Nombre de questions bloquantes ouvertes (doit tendre vers 0 avant sprint 1).
- Nombre de findings sécurité ouverts (doit être 0 avant mise en production).
- Couverture de test des chemins critiques (émargement, export).
- Temps de chargement de la page d'émargement (budget perf mobile).
