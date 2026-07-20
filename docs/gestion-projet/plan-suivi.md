# Plan de suivi — projet Presence

- **Statut** : plan consolidé — **jalon J0 franchi**, architecture verrouillée
- **Date** : 2026-07-19 (mise à jour)
- **Rédacteur** : agent `chef-de-projet`
- **Méthodo** : Scrumban léger (voir `methodologie.md`)

> Référence partagée des agents dev, UX, QA, sécurité, devops et doc. Le
> `journal.md` en est le pendant vivant (décisions, avancement, blocages).

---

## 0. Architecture verrouillée (J0)

- **Produit MONO-TENANT** : plateforme **interne à ACS Groupe** (pas de SaaS
  multi-organisations). « Multi-organisateur » = **plusieurs comptes internes**
  ACS Groupe. Finalité : enregistrer les **visiteurs** des événements d'ACS Groupe
  et leur envoyer un **récapitulatif par email** en fin d'événement.
- **Stack : Laravel (PHP) + MySQL** sur **Hostinger « Premium Web Hosting »**
  (mutualisé cPanel, **sans Node.js persistant ni websocket**). Dev local : **MAMP**
  (`/Applications/MAMP/htdocs/Presence`, PHP 8.5, MySQL). **Non négociable.**
- **Deux surfaces applicatives** :
  1. **Dashboard organisateur** authentifié (comptes internes ACS Groupe).
  2. **Page publique d'émargement** (sans compte), mobile-first.
- **Mode QR par événement** (champ `mode_qr` sur `Événement`, choisi à la création) :
  - **statique** : QR stable **imprimable** (posé sur table, sans écran) ;
    anti-fraude = **géoloc + fenêtre + anti-doublon** (QR photographiable, compromis assumé).
  - **tournant** : tokens à durée de vie **15 s** (Q11) générés/validés **côté
    serveur**, affichage rafraîchi par **polling**, **validation stricte à la
    soumission** ; **impose un écran/vidéoprojecteur**.
- **Géolocalisation strictement obligatoire** (Q6) : pas de soumission sans
  position ; si refus → message « position obligatoire » + **redemande** de
  permission. Aucun contournement.
- **Signature manuscrite** via pad tactile ; format/stockage **délégué au dev** (Q9).
- **Idempotence + dédoublonnage automatique** : aucune création de doublon (double-clic,
  rescan) ; gestion des doublons sans intervention manuelle.
- **Règle anti-chevauchement** : pas de présence à deux activités simultanées ;
  mécanisme (règle serveur vs checkout explicite) **à arbitrer par `senior-fullstack`** (Q15).
- **Email récap auto** en fin d'événement via **cron cPanel** (§ contraintes) ; SMTP à définir.
- **Recherche dashboard via palette Cmd+K** ; **graphiques/statistiques** (Q17).
- **Modèle Personne/Présence** : `Personne` unique par email + `Présence` par
  scan/événement (géoloc + signature + horodatage ; état actif/sorti éventuel selon Q15).
- **Conservation indéfinie** (Q8) — **flag légal** à vérifier par `security-expert`.
- **Cadre légal : droit ivoirien (Loi n°2013-450, ARTCI)** — PAS le RGPD UE.

## 1. Découpage produit : MVP vs itérations

### MVP (V0 — le cœur de valeur)
Objectif : un organisateur interne ACS Groupe crée un événement (mode statique ou
tournant), un visiteur émarge depuis son mobile (formulaire + géoloc obligatoire +
signature) sans app ni compte, le récurrent est reconnu par email, l'organisateur
suit/recherche/exporte, et un email récap est envoyé automatiquement en fin d'événement.

- Auth organisateur (comptes internes ACS Groupe). Cloisonnement inter-organisateurs à confirmer (Q14).
- CRUD événement + fenêtre d'émargement + **choix du mode QR (statique/tournant)**.
- **QR selon le mode** : statique (imprimable) ou tournant (token 15 s serveur +
  affichage polling + validation à la soumission).
- Page publique d'émargement mobile-first : formulaire complet (Nom, Prénom(s),
  Email, Téléphone, **Entité/Entreprise**, Direction, Service optionnel, Poste ou
  Fonction) + **géoloc obligatoire (pas de bypass)** + signature tactile.
- Reconnaissance du récurrent par email (parcours réduit géoloc + signature).
- **Anti-chevauchement** de présences simultanées (mécanisme Q15).
- **Idempotence + dédoublonnage automatique** (email + événement).
- Vue organisateur temps quasi réel + compteur + **recherche Cmd+K** + **graphiques/stats** (Q17).
- **Email récap automatique** en fin d'événement (cron cPanel + SMTP).
- Export CSV.
- Mentions de protection des données (droit ivoirien). **Conservation indéfinie**
  (pas de purge) — sous réserve du flag légal (Q16).

### Itération 1 (V1 — confort & conformité renforcés)
- Sessions multiples sous un même événement (activité récurrente).
- Export enrichi (XLSX/PDF), filtres, recherche.
- Tableau de bord statistiques (taux de présence, récurrents).
- Gestion fine des droits des personnes (accès/effacement en self-service).
- Réglages anti-fraude (durée de vie du token configurable, tolérance).

### Itération 2+ (au-delà — sous réserve du besoin réel)
- Rôles/permissions internes par tenant, invitations d'organisateurs.
- Marque blanche / branding par tenant.
- Notifications (email de confirmation de présence).
- Intégrations (agenda, export comptable/RH).

> Règle YAGNI : rien de l'itération 2+ n'est développé tant que le besoin n'est
> pas confirmé par l'utilisateur.

## 2. Backlog initial (items priorisés)

> `[P0]` = MVP bloquant · `[P1]` = itération 1 · `[P2]` = ultérieur.
> Flux : Prêt → En cours → Revue (code+QA) → Gate sécurité (si sensible) → Fait.

| ID | Item | Prio | Acteur | Notes / critères d'acceptation |
|---|---|---|---|---|
| T00 | Init projet **Laravel** + repo + structure + CI (compat PHP Hostinger) | P0 | fullstack/devops | Framework acté (Q10) ; vérifier version PHP mutualisé |
| T01 | Modèle de données (Utilisateur interne, Événement [+ `mode_qr`], Token QR, Personne [+ entité/entreprise], Présence [+ état actif/sorti selon Q15]) | P0 | fullstack/sécu | Schéma revu, migrations MySQL, contraintes d'unicité (email global ; email+événement) ; Q13 |
| T02 | Auth organisateur (comptes internes ACS Groupe) | P0 | fullstack/sécu | Sessions sûres, moindre privilège ; cloisonnement inter-organisateurs Q14 |
| T03 | CRUD événement + fenêtre d'émargement + **choix mode QR** | P0 | fullstack | Créer/éditer/clôturer ; refus hors fenêtre ; `mode_qr` à la création |
| T04 | **QR statique** : génération + QR imprimable/téléchargeable | P0 | fullstack | Lien stable ; anti-fraude géoloc+fenêtre+anti-doublon |
| T04b | **QR tournant** : génération + validation token serveur (**15 s**) | P0 | fullstack/sécu | Token 15 s (Q11), validation stricte à la soumission, rejet clair si expiré |
| T05 | Affichage QR côté organisateur (statique imprimable / tournant en boucle polling) | P0 | fullstack/ux | Selon `mode_qr` ; rafraîchissement auto sans websocket en tournant |
| T06 | Page publique d'émargement mobile-first (formulaire complet dont Entité/Entreprise) | P0 | ux/fullstack | Champs exacts (§ cadrage 5.1), < 2 s en 4G, WCAG AA ; Q12 |
| T07 | **Géolocalisation strictement obligatoire** (pas de bypass) | P0 | fullstack/ux | HTTPS ; blocage validation si pas de position, message + redemande de permission (Q6) |
| T08 | Signature manuscrite tactile (pad) + stockage | P0 | fullstack | Format délégué dev (Q9), taille maîtrisée, accès protégé |
| T09 | Reconnaissance récurrent par email (parcours réduit) | P0 | fullstack | Email connu → géoloc + signature seulement |
| T10 | Enregistrement présence + **idempotence + dédoublonnage automatique** | P0 | fullstack | Aucun doublon (double-clic/rescan), horodatage serveur, clé email+événement |
| T10b | **Règle anti-chevauchement** de présences simultanées | P0 | fullstack | Arbitrer mécanisme a/b (Q15) ; message clair de rejet ou checkout |
| T11 | Vue organisateur temps quasi réel + compteur | P0 | fullstack/ux | Polling, liste lisible |
| T11b | **Recherche par nom via palette Cmd+K** | P0 | ux/fullstack | Pattern palette de commande |
| T11c | **Graphiques / statistiques** du dashboard | P0 | ux/fullstack | Lesquels : Q17 (ux-designer) |
| T12 | Export CSV | P0 | fullstack | UTF-8, colonnes claires, filtré par événement |
| T13 | Mentions protection des données (droit ivoirien) — **conservation indéfinie** | P0 | fullstack/sécu | Mention page publique ; pas de purge (Q8) sous réserve flag légal (Q16) |
| T13b | **Email récap automatique** en fin d'événement (cron cPanel + SMTP) | P0 | fullstack/devops | Envoi à tous les émargés ; SPF/DKIM ; file en base + cron ; contenu Q18 |
| T14 | **Recherche loi ivoirienne n°2013-450 + implications techniques + conflit conservation indéfinie** | P0 | sécurité/rédacteur | Recherche réelle (ne rien inventer) ; obligations ARTCI ; remonter conflit conservation à l'utilisateur (Q16) |
| T15 | Audit sécurité MVP (gate avant prod) | P0 | sécurité | OWASP, secrets, deps, token QR, géoloc, signatures, données perso |
| T16 | Déploiement MVP Hostinger + observabilité | P0 | devops | HTTPS, sauvegardes, logs, cron (fermeture+emails), rollback |
| T17 | Documentation (guide orga, guide visiteur, technique) | P0 | rédacteur | Documente le réel |
| T20 | Sessions multiples par événement | P1 | fullstack | Récurrence |
| T21 | Tableau de bord statistiques | P1 | fullstack/ux | Taux de présence, récurrents |
| T22 | Export XLSX/PDF + filtres/recherche | P1 | fullstack | — |
| T23 | Droits des personnes en self-service (accès/effacement) | P1 | fullstack/sécu | Conforme droit ivoirien |
| T30 | Rôles/permissions par tenant, marque blanche | P2 | fullstack/sécu | Selon besoin |

## 3. Jalons

| Jalon | Contenu | Condition de sortie |
|---|---|---|
| **J0 — Cadrage & archi verrouillés** | ✅ Questions Q1-Q8 tranchées, stack PHP/MySQL Hostinger actée, QR tournant confirmé | **Franchi** (2026-07-19) |
| **J1 — Fondations** | Repo/structure PHP, modèle de données, auth + multi-tenant | Squelette déployable, tests qui tournent |
| **J2 — MVP fonctionnel (démo)** | Parcours complet dans les **2 modes QR** (statique + tournant) → émarger (formulaire+géoloc+signature) → récurrent → liste → export | Démo bout-en-bout sur mobile réel |
| **J3 — Gate sécurité MVP** | Audit `security-expert` + recherche loi 2013-450 + corrections | 0 finding critique/haut ouvert ; conformité documentée |
| **J4 — Mise en production MVP** | Déploiement Hostinger, HTTPS, purge cron, observabilité, doc | Prod stable, monitoring actif |
| **J5 — V1** | Sessions multiples, dashboard stats, droits des personnes | Audit sécu V1 passé |

## 4. Definition of Ready (DoR)

Un item est « Prêt » quand : objectif et valeur écrits ; critères d'acceptation
explicites et testables ; impacts sécurité et protection des données identifiés ;
dépendances connues.

## 5. Definition of Done (DoD)

Un item est « Fait » quand :
- Code conforme aux règles maison (typage/rigueur PHP, gestion d'erreurs
  explicite, zéro valeur codée en dur, séparation des responsabilités).
- Tests des chemins critiques écrits et **verts** (preuve réelle).
- Revue d'ingénierie (`senior-fullstack`) + QA (`qa-testeur`) passées.
- Pour tout item sensible (auth, multi-tenant, token QR, données perso, géoloc,
  signature) : **gate sécurité** franchie (`security-expert`).
- Accessibilité vérifiée sur le parcours participant (WCAG 2.2 AA).
- Documentation à jour (`redacteur-technique`) si l'usage est impacté.
- `journal.md` mis à jour.

## 6. Rituels (rappel — voir `methodologie.md`)

Flux mis à jour en continu (board + `journal.md`) ; revue d'incrément et rétro
express en fin d'itération ; **gate sécurité** avant tout déploiement et après tout
développement sensible.

## 7. Jalons de sécurité (intégrés au flux)

- **En conception** : revue du modèle de données et du multi-tenant (T01, T02) —
  minimisation, isolation, moindre privilège.
- **QR tournant** (T04) : revue de la génération/validation de token (pas de rejeu
  après expiration, horodatage serveur faisant foi).
- **Recherche légale** (T14) : cadrage des obligations de la Loi n°2013-450 (ARTCI)
  par recherche réelle — **aucune supposition**.
- **Revue des dépendances** : à l'ajout de toute dépendance PHP (CVE, supply-chain).
- **Gate MVP (J3)** : audit complet avant première mise en production Hostinger.
- **Règle maison** : aucun agent ne valide son propre code comme « sûr » ; l'audit
  sécurité est indépendant du développement.

## 8. Ce qui reste à décider (questions ouvertes)

**Tranchées** : Q6 (géoloc obligatoire), Q9 (format signature délégué dev), Q10
(Laravel), Q11 (token 15 s), plus la nature mono-tenant (ex-Q5).

**Encore ouvertes** (voir `cadrage.md` § 12) — **aucune ne bloque le lancement de
`security-expert` + `ux-designer`** :
- Q12 (Entité/Entreprise obligatoire ?) — utilisateur, défaut obligatoire.
- Q13 (entreprise réutilisée depuis `Personne` ou ressaisie ?) — fullstack → utilisateur.
- Q14 (cloisonnement entre organisateurs internes) — utilisateur, avant de figer le dashboard.
- Q15 (mécanisme anti-chevauchement : règle serveur vs checkout) — **senior-fullstack**.
- Q16 (conformité conservation indéfinie vs Loi 2013-450) — **security-expert** (recherche réelle).
- Q17 (statistiques/graphiques du dashboard) — **ux-designer** → utilisateur.
- Q18 (contenu de l'email récapitulatif) — **ux-designer** → utilisateur.

## 9. Prochaine étape recommandée

Lancer **en parallèle**, avant `senior-fullstack` :
- `security-expert` → **recherche réelle** de la Loi n°2013-450 (ARTCI) et de ses
  implications techniques (T14), **avec focus sur le conflit conservation indéfinie
  (Q16)** à remonter à l'utilisateur le cas échéant.
- `ux-designer` → parcours + wireframes de la **page publique** (formulaire dont
  Entité/Entreprise, géoloc obligatoire + gestion du refus, signature, cas
  récurrent) et du **dashboard** organisateur (stats/graphiques Q17, recherche
  Cmd+K, email récap Q18), en mobile-first.

Puis `senior-fullstack` : conception Laravel, modèle de données (dont état de
présence pour l'anti-chevauchement Q15), mécanismes QR statique/tournant, envoi
email via cron, idempotence/dédoublonnage — avant implémentation (J1).
