# Journal de projet — Presence

> Suivi vivant : décisions, avancement, blocages. Mis à jour au fil de l'eau par
> tous les agents. Entrées les plus récentes en haut.

---

## 2026-07-20 — Faille sécurité corrigée : fuite PII + écrasement d'identité (agent senior-fullstack)

Revue sécurité automatique post-commit sur `PublicAttendanceController` : 2
trouvailles vérifiées réelles avant correction (jamais de correctif sécu sans
vérification préalable des faits).

- **HIGH — énumération d'emails / fuite PII** : `recognize()` était accessible
  sans preuve de scan (pas de ticket), sans limitation de débit, et renvoyait
  téléphone/direction/service/poste pour tout email deviné → annuaire complet
  du personnel exposé sans jamais approcher un QR. Corrigé : ticket de scan
  obligatoire (`QrTokenService::verifyScanTicket`, déjà émis par `show()`) +
  limitation 15 requêtes/min par IP+événement (`RateLimiter`, même pattern que
  `LoginRequest`).
- **MEDIUM — écrasement d'identité** : `upsertPerson()` écrasait nom/téléphone/
  entreprise/poste d'une `Person` **existante** à chaque soumission publique,
  sans vérifier l'identité du soumissionnaire → présent physiquement + email
  deviné = corrompre la fiche d'un collègue. Corrigé : une fiche existante
  n'est plus jamais modifiée par la soumission publique ; seule la création
  d'une fiche nouvelle renseigne les champs (l'`Attendance` garde de toute
  façon son propre instantané des données soumises, indépendant du
  référentiel `Person`).
- 3 tests ajoutés (ticket requis, rate-limit, non-écrasement) ; suite complète
  80/80 verte. Pré-existant à la fonctionnalité T04/T05 de cette session (code
  du flux public livré en session précédente), traité immédiatement vu la
  gravité plutôt que reporté à T15 — l'audit sécurité complet (T14/T15) reste
  à faire avant tout déploiement.

---

## 2026-07-20 — T04/T05 création d'événement livrée (agent senior-fullstack)

- Route/contrôleur/vue manquants pour créer un événement (seuls index/show
  existaient) : trou bloquant tout le parcours MVP, comblé.
- `EventController@create/@store` + `StoreEventRequest` (validation titre, type,
  date/horaires cohérents, lieu facultatif, mode QR, invités facultatifs).
- Slug public unique généré à la création (retry avec suffixe aléatoire en cas
  de collision de titre) ; `qr_secret` propre à chaque événement.
- `PersonSearchController` (`GET admin/people/search`) : recherche serveur du
  référentiel « Personnel ACS Groupe » (is_staff) pour la combobox d'invitation
  (`EventInvitation`, n'enregistre pas de présence — juste liste d'attendus).
- Vue `admin/events/create.blade.php` fidèle au prototype validé
  (`prototype/admin/creer-evenement.html`) : sections Informations / Mode QR /
  Invités, aperçu live, pas de mode brouillon (conforme au commentaire de
  migration « création = publication directe »). CSS ajouté à `dashboard.css`,
  JS dans `public/js/event-create.js` (preview + combobox, XSS-safe via échappement).
- Nav sidebar : lien « Nouvel événement » ajouté dans `layouts/admin.blade.php`.
- 5 tests Feature ajoutés (`EventCreateTest`) : formulaire, création+invités,
  unicité de slug, rejet fin<début, recherche personnel. Suite complète 77/77 verts.
- Vérifié en conditions réelles au navigateur (MAMP, connexion admin, création
  bout-en-bout avec invité, redirection + flash, fiche événement correcte),
  événement de test nettoyé après vérification.

---

## 2026-07-19 — Q14 tranchée : accès partagé (agent chef-de-projet)

- **Q14 ✅ TRANCHÉE — option B : pas de cloisonnement entre organisateurs internes.**
  Tout compte interne ACS Groupe authentifié voit **l'intégralité** des événements
  et présences de l'organisation, quel que soit le créateur. Pas de vue « mes
  événements » restreinte. Simplifie le modèle d'autorisation (rôle unique
  « organisateur interne », pas de scoping par créateur).
- Impact : `cadrage.md` (§ 2, § 5.2 F7, § 6, § 12) mis à jour ; T02 du backlog
  reste valable sans logique de cloisonnement. Il ne reste plus que Q12, Q13, Q15,
  Q16, Q17, Q18 (toutes non bloquantes / déléguées aux agents spécialistes).

---

## 2026-07-19 — J0 clos : réponses Q5-Q11, changements majeurs (agent chef-de-projet)

Réponses de l'utilisateur intégrées. `cadrage.md` **réécrit en v0.3** (cohérence
globale revue), `plan-suivi.md` mis à jour. Changements structurants ci-dessous.

### Changements majeurs
- **CHANGEMENT MAJEUR — MONO-TENANT (ex-Q5)** : ce n'est **PAS** un SaaS
  multi-organisations. Plateforme **interne à ACS Groupe** pour enregistrer les
  **visiteurs** de ses activités/réunions/ateliers. « Multi-organisateur » =
  **plusieurs comptes internes ACS Groupe**, PAS des entreprises clientes isolées.
  → **Ancien risque R3 (fuite inter-tenant) supprimé.** Le champ
  « Entité/Entreprise » reste pertinent (visiteurs de sociétés variées).
  → **Nouvelle question Q14** : cloisonnement **entre organisateurs internes**
  (chacun voit-il les événements des autres ?) — non tranché, flagué.
- **Nouvelle règle métier — anti-chevauchement (§ 5.3)** : un individu ne peut pas
  être présent à **deux activités simultanées** (« il doit d'abord sortir de l'une
  pour scanner une autre »). **Mécanisme non précisé** → **Q15 pour
  `senior-fullstack`** : (a) règle serveur sur fenêtres temporelles, ou (b)
  checkout explicite par rescan. Impacte le modèle (état actif/sorti sur Présence).
- **Nouvelle fonctionnalité — email récap auto (§ 5.4)** : à la fin de l'événement,
  envoi automatique d'un récapitulatif par email à tous les émargés. Via **cron
  cPanel** + SMTP Hostinger (pas de worker persistant). Contenu = Q18.
- **Q6 CORRIGÉ — géolocalisation STRICTEMENT OBLIGATOIRE** : pas de soumission sans
  position. Si refus → message « position obligatoire » + **redemande** de
  permission. **Aucun bypass** (ma reformulation précédente « dégradation
  gracieuse / sans position » était erronée et est annulée).
- **Q7 — Dashboard enrichi** : graphiques/statistiques (Q17, avec ux-designer) ;
  **recherche par nom via palette Cmd+K** ; **idempotence** de la plateforme ;
  **dédoublonnage automatique** (sans action manuelle de l'organisateur).
- **Q8 — Conservation INDÉFINIE** : pas de purge automatique. ⚠️ **Flag légal
  (Q16)** : peut entrer en conflit avec un principe de limitation de durée si la
  Loi n°2013-450 en prévoit un → `security-expert` vérifie par recherche réelle et
  **remonte le conflit à l'utilisateur** (ne pas trancher/ignorer).
- **Q9 — Format signature délégué au dev** (aucune contrainte utilisateur).
- **Q10 — Framework : LARAVEL** (verrouillé, plus une option).
- **Q11 — Token QR tournant : 15 secondes** (mode tournant uniquement).

### Décisions verrouillées au J0 (récap)
Mono-tenant ACS Groupe · Laravel + MySQL + Hostinger Premium (mutualisé, cron
cPanel, pas de websocket) · mode QR par événement (statique / tournant 15 s) ·
géoloc obligatoire · signature tactile · Personne/Présence · idempotence +
dédoublonnage auto · anti-chevauchement · email récap auto · conservation
indéfinie (sous réserve légale) · droit ivoirien (Loi 2013-450 / ARTCI).

### Questions encore ouvertes (aucune ne bloque security-expert + ux-designer)
| # | Question | Qui | Statut |
|---|---|---|---|
| Q12 | Entité/Entreprise obligatoire ou optionnel ? | utilisateur | ⏳ (défaut obligatoire) |
| Q13 | Entreprise réutilisée depuis `Personne` ou ressaisie par présence ? | fullstack → utilisateur | ⏳ |
| Q14 | Cloisonnement entre organisateurs internes ACS Groupe ? | utilisateur | ⏳ (avant de figer le dashboard) |
| Q15 | Mécanisme anti-chevauchement (règle serveur vs checkout) | senior-fullstack | ⏳ (arbitrage dev) |
| Q16 | Conservation indéfinie vs Loi 2013-450 | security-expert | ⏳ (recherche réelle) |
| Q17 | Statistiques/graphiques du dashboard | ux-designer → utilisateur | ⏳ |
| Q18 | Contenu de l'email récapitulatif | ux-designer → utilisateur | ⏳ |

### Statut du jalon J0
**CLOS pour lancer la suite.** Les décisions structurantes sont arrêtées ; les
questions restantes sont soit déléguées aux agents spécialistes (Q15/Q16/Q17/Q18),
soit des confirmations produit non bloquantes (Q12/Q13/Q14, avec Q14 à confirmer
avant de figer le dashboard).

### Prochaine étape
Lancer **en parallèle** `security-expert` (recherche réelle Loi 2013-450 + conflit
conservation Q16) et `ux-designer` (wireframes page publique + dashboard, Q17/Q18),
puis `senior-fullstack` (conception Laravel, modèle de données, Q15, QR, email cron,
idempotence) avant J1.

### Rétro express
- ✅ A marché : cadrage réécrit proprement après un changement de cap majeur (mono-tenant).
- ⚠️ A surveiller : conservation indéfinie (Q16) — vrai risque légal à faire vérifier.
- 🔁 On change : mono-tenant (pas SaaS) ; géoloc obligatoire (pas de bypass) ; Laravel acté.

---

## 2026-07-19 — Corrections post-J0 : mode QR par événement + champ Entité/Entreprise (agent chef-de-projet)

Deux corrections de l'utilisateur intégrées dans `cadrage.md`, `plan-suivi.md`,
`journal.md`, avant de lancer `security-expert` / `ux-designer`.

### Décisions ajustées
- **D8bis — Le mode QR devient un choix PAR ÉVÉNEMENT** (champ `mode_qr` sur
  l'entité `Événement`, choisi à la création). Deux modes :
  - **`statique`** : cas fréquent = **QR imprimé et posé sur la table** (pas
    d'écran/projection, donc **pas de rotation possible**). Anti-fraude =
    **géolocalisation + fenêtre temporelle + anti-doublon** uniquement. Compromis
    **assumé** : le QR est photographiable/partageable.
  - **`tournant`** : token courte durée réaffiché en boucle → **nécessite un
    écran/vidéoprojecteur** affichant la page organisateur. Anti-fraude renforcée
    (token validé côté serveur à la soumission). Ferme la brèche du statique.
  - Le mode conditionne génération/affichage du QR **et** les règles de validation
    serveur. (Correction de la décision D8 précédente qui figeait « tournant ».)
- **D10 — Champ formulaire manquant : « Entité/Entreprise »** ajouté, positionné
  **avant « Direction »** (structure logique Entreprise → Direction → Service →
  Poste). Proposé **obligatoire par défaut** (rôle central : identifier la
  provenance, cohérent avec « toute personne quelle que soit sa provenance »).
  Rattaché à l'entité `Personne` (réutilisable à la reconnaissance par email).

### Nouvelles questions ouvertes (à confirmer, non tranchées de mon fait)
| # | Question | Impact | Qui | Statut |
|---|---|---|---|---|
| Q12 | **« Entité/Entreprise » obligatoire ou optionnel ?** (proposé obligatoire par défaut) | UX + données | utilisateur | ⏳ ouverte |
| Q13 | Entreprise **réutilisée depuis `Personne`** (reconnaissance email) **ou ressaisie à chaque présence** (l'entreprise pouvant changer d'un événement à l'autre) ? | Modèle de données | fullstack propose → utilisateur | ⏳ ouverte |

> Rappel : le formulaire complet devient **Nom\*, Prénom(s)\*, Email\*, Téléphone\*,
> Entité/Entreprise\* (à confirmer), Direction\*, Service (optionnel), Poste ou
> Fonction\*** + géoloc auto + signature. Impacts sur T01 (schéma), T03 (choix
> mode QR), T04/T04b (QR statique vs tournant), T05 (affichage), T06 (formulaire).

---

## 2026-07-19 — Jalon J0 franchi : décisions verrouillées (agent chef-de-projet)

L'utilisateur a répondu aux 8 questions bloquantes (avec une correction sur
l'anti-fraude). `cadrage.md` (v0.2) et `plan-suivi.md` mis à jour en conséquence.

### Décisions verrouillées (J0)
- **D1 — Échelle** : participants **illimités** par événement, pas de plafond annoncé.
- **D2 — Parcours participant sans authentification** : page publique mobile après
  scan. Formulaire (libellés exacts) : **Nom\*, Prénom(s)\*, Email\*, Téléphone\*,
  Direction\*, Service (optionnel), Poste ou Fonction\***. + **géolocalisation
  captée automatiquement** + **signature manuscrite tactile** (valide
  l'enregistrement) + **notification de confirmation** à l'écran.
- **D3 — Reconnaissance du récurrent par email** : entité **`Personne` globale
  (unique par email)** ; si l'email existe déjà (tous événements confondus), on ne
  redemande que **géoloc + signature**. Modèle **Personne / Présence** (Présence =
  une par scan/événement, avec sa propre géoloc + signature + horodatage).
- **D4 — Valeur de la présence** : **pointage informatif + signature** (preuve
  morale/visuelle). **PAS** de valeur légale/probante (pas de Qualiopi).
- **D5 — Multi-tenant** : **oui**, multi-organisateurs ; chaque organisateur gère
  ses événements depuis un **dashboard authentifié**, avec **isolation des données**.
- **D6 — Public visé** : tiers **et** interne (« enregistrer toutes les personnes
  présentes quelle que soit leur provenance »).
- **D7 — Cadre légal** : **droit ivoirien** (Loi **n°2013-450** du 19/06/2013,
  autorité **ARTCI**), **PAS le RGPD UE**. ⚠️ Contenu exact de la loi **à vérifier
  par recherche réelle** (security-expert / rédacteur) — **ne rien inventer**.
- **D8 — Anti-fraude (CORRIGÉ)** : **QR TOURNANT à courte durée de vie** (token
  régénéré ~15-30 s côté serveur, réaffiché en boucle sur l'écran organisateur /
  vidéoprojecteur), **validation stricte du token à la soumission** (rejet + message
  « rescanner » si expiré). + **géolocalisation** (dégradation gracieuse si refus).
  Le QR statique évoqué initialement est **abandonné**. Compatible mutualisé
  PHP/MySQL : **polling**, pas de websocket.
- **D9 — Hébergement/stack (verrouillé)** : **Hostinger « Premium Web Hosting »**
  (mutualisé cPanel) + **PHP + MySQL**, **sans Node.js persistant ni websocket**.
  **Le débat Next.js/Vercel est clos** → ce sera **PHP + MySQL**. Dev local **MAMP**
  cohérent avec la cible. **HTTPS obligatoire** (géoloc + protection des données).

### Questions encore ouvertes (après J0)
| # | Question | Impact | Qui | Statut |
|---|---|---|---|---|
| Q5 | **Base `Personne` mutualisée entre tous les tenants, ou cloisonnée par tenant ?** (critique vie privée : un orga ne devrait pas voir l'historique d'un participant chez un autre orga, même si l'identité est mutualisée pour éviter la resaisie) | Archi + vie privée | sécurité propose → utilisateur tranche | ⏳ ouverte |
| Q6 | Comportement si **géolocalisation refusée/indisponible** (bloquer ? autoriser en « sans position » ? relancer ?) | UX + fiabilité | ux/sécu propose → utilisateur | ⏳ ouverte |
| Q7 | **Contenu exact du dashboard organisateur** (quelles métriques, quelles actions) | Périmètre | ux → utilisateur | ⏳ ouverte |
| Q8 | **Durée de conservation** des données (présences, signatures, géoloc) | Légal + technique | sécurité (selon loi 2013-450) → utilisateur | ⏳ ouverte |
| Q9 | **Format/stockage de la signature** (PNG/SVG ; export) | Technique | fullstack | ⏳ ouverte |
| Q10 | **Framework PHP** (vanilla / Laravel / Symfony) selon contraintes mutualisé | Technique | senior-fullstack | ⏳ ouverte |
| Q11 | **Durée de vie exacte du token QR** + tolérance d'expiration | Sécurité/UX | fullstack/sécu → utilisateur | ⏳ ouverte |

> Note : Q1-Q4 (échelle, auth participant, valeur probante, multi-tenant), Q6/Q7/Q8
> initiaux (hébergement, anti-fraude, budget partiel) du premier tour sont **tranchés**
> (voir décisions D1-D9). La numérotation Q5-Q11 ci-dessus est la **nouvelle** liste ouverte.

### Prochaine étape recommandée (séquence équipe)
1. **En parallèle** : `security-expert` (recherche réelle Loi n°2013-450 / ARTCI +
   recommandation Q5) **et** `ux-designer` (wireframes page publique + dashboard, Q6/Q7).
2. Puis `senior-fullstack` : arbitrage framework PHP (Q10), modèle de données,
   mécanisme QR tournant, avant implémentation (J1 — Fondations).

### Rétro express
- ✅ A marché : réponses claires de l'utilisateur, J0 verrouillé rapidement.
- ⚠️ A surveiller : Q5 (mutualisation `Personne`) est un vrai risque vie privée.
- 🔁 On change : QR **tournant** (et non statique) ; stack **PHP/MySQL** définitive.

---

## 2026-07-19 — Cadrage initial (agent chef-de-projet)

### Fait
- Benchmark des plateformes existantes réalisé (Eventbrite, OneTap, Whova,
  Accelevents, SmartOF, SoWeSign, Digiforma, Edutio, ReSigne, Dendreo…) — voir
  synthèse ci-dessous.
- Rédaction du cadrage (`cadrage.md`), de la méthodologie (`methodologie.md`) et
  du plan de suivi (`plan-suivi.md`).
- Environnement vérifié : répertoire projet vide, PHP 8.5 et Node 24 disponibles,
  CLI Vercel non installée localement (plugins Vercel présents côté environnement
  utilisateur).

### Décisions (provisoires, à valider par l'utilisateur)
- **Méthodologie : Scrumban léger** (Kanban priorisé + itérations courtes
  jalonnées + pratiques d'ingénierie Lean/XP). Justification dans `methodologie.md`.
- **MVP** = créer événement → générer QR → émarger (mobile, sans app) → liste
  temps réel → export CSV → mentions/conservation RGPD → auth organisateur.
- **Piste de stack par défaut** : Next.js (TypeScript) sur Vercel + PostgreSQL
  managé en région UE ; **alternative** PHP (Laravel/Symfony) + hébergeur UE si
  souveraineté/hébergement des données prioritaires. **Arbitrage au jalon J0.**
- Le participant scanne avec l'appareil photo natif → pas d'app à installer ;
  l'application génère le QR côté serveur et sert une page d'émargement.

### Synthèse du benchmark
- **Eventbrite Organizer** : QR **nominatif par billet** (généré à l'inscription),
  scanné par le staff ; check-in offline + sync, anti-partage de billet.
  → À s'inspirer : robustesse, offline. À éviter pour nous : modèle billetterie
  lourd, orienté staff qui scanne (nous voulons le **self check-in** participant).
- **OneTap** : plusieurs modes dont **self-check-in par lien/QR** et code unique.
  → Confirme la pertinence du self check-in navigateur.
- **Whova / Accelevents** : bornes/kiosques self-serve, check-in par session.
  → Inspiration pour les **sessions multiples** (itération 1).
- **SmartOF / SoWeSign / Digiforma / Edutio / ReSigne / Dendreo** (FR, formation) :
  **QR unique par session** projeté à l'écran, l'apprenant scanne et **signe** sur
  son téléphone ; traçabilité et **RGPD** mis en avant (durée de conservation,
  minimisation, sécurité des accès — rappel CNIL).
  → Très proche de notre besoin. À retenir : QR par session projeté, mention RGPD
  de base ; à évaluer plus tard : signature à valeur probante (Qualiopi).
- **Google Forms + QR** : le degré zéro (QR → formulaire → tableur).
  → Montre la baseline « sans app » ; nos différenciateurs = fenêtre d'émargement,
  anti-doublon, anti-fraude, vue temps réel, export propre, conformité intégrée.
- **Anti-fraude (littérature)** : le QR statique est **photographiable et
  partageable** → fraude par procuration (proxy/buddy punching). Parades éprouvées :
  **QR tournant à durée de vie courte + usage unique**, **fenêtre temporelle**,
  **géofencing/GPS**, **PIN/selfie** en second facteur. → Cadré en R1 ; QR tournant
  prévu en itération 1 (T20).

### Questions ouvertes (bloquantes — à trancher avant le sprint 1)
| # | Question | Impact | Statut |
|---|---|---|---|
| Q1 | Échelle : nb de participants/événement et d'événements/mois ? | Archi, coûts, DB | ⏳ ouverte |
| Q2 | Authentification participant : scan anonyme + nom, ou identité vérifiée (email/SSO) ? | Modèle de données, anti-fraude, RGPD | ⏳ ouverte |
| Q3 | Valeur probante : simple pointage, ou émargement légal (formation/Qualiopi, signature) ? | Périmètre, features | ⏳ ouverte |
| Q4 | Multi-organisateurs / multi-tenant, ou usage mono-utilisateur ? | Architecture (isolation) | ⏳ ouverte |
| Q5 | Marque blanche / produit destiné à des tiers ? | Positionnement, effort | ⏳ ouverte |
| Q6 | Hébergement des données : exigence UE/France stricte ? | Choix stack/hébergeur | ⏳ ouverte |
| Q7 | Niveau anti-fraude requis (QR statique suffit, ou tournant + géo) ? | Sécurité, effort | ⏳ ouverte |
| Q8 | Budget d'hébergement et échéance cible ? | Choix techniques, planning | ⏳ ouverte |

### Prochaines étapes
1. Obtenir les réponses de l'utilisateur aux questions Q1–Q8 (jalon **J0**).
2. Arbitrer la stack (option Vercel/Next.js vs PHP/UE) → `senior-fullstack`.
3. Valider le périmètre MVP, puis lancer **J1 — Fondations** (T00–T02).

### Rétro express
- ✅ A marché : benchmark rapide et ciblé, cadrage complet écrit dans le projet.
- ⚠️ A bloqué : plusieurs paramètres produit inconnus (échelle, auth, hébergement).
- 🔁 On change : ne pas coder tant que J0 n'est pas franchi (éviter la sur-ingénierie).
