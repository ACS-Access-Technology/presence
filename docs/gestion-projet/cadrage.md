# Cadrage projet — Presence (plateforme de présence par QR code)

> Document de référence partagé. Toute décision ou hypothèse non tranchée est
> signalée par `❓ À CONFIRMER`. Aucune donnée n'est inventée.

- **Statut** : cadrage consolidé — **jalon J0 quasi clos** (voir § 12)
- **Date** : 2026-07-19 (mise à jour)
- **Rédacteur** : agent `chef-de-projet`
- **Version** : 0.3

---

## 1. Objectif

Fournir une plateforme web **interne à ACS Groupe** permettant d'enregistrer la
présence des **visiteurs** lors des activités, réunions et ateliers organisés par
ACS Groupe. Un organisateur (compte interne ACS Groupe) crée un événement, génère
un **QR code — statique ou tournant selon le mode choisi à la création** ; les
**visiteurs** scannent ce QR depuis le navigateur de leur mobile (**sans app, sans
compte**), remplissent un formulaire, sont **géolocalisés** et **signent à la
main** pour valider. À la **fin de l'événement**, la plateforme **envoie
automatiquement par email** un récapitulatif à tous les participants ayant émargé.
L'organisateur consulte et exporte les présences depuis un **dashboard**.

Proposition de valeur : remplacer la feuille de présence papier par un émargement
numérique rapide (identité + entreprise d'origine + géoloc + signature), sans
friction côté visiteur, avec reconnaissance des récurrents, dédoublonnage
automatique et envoi automatique d'un récapitulatif.

## 2. Nature du produit : mono-tenant (ACS Groupe)

**Ce n'est PAS un produit SaaS multi-organisations.** Le **seul tenant est ACS
Groupe**. La notion de « multi-organisateur » signifie : **plusieurs comptes
utilisateurs internes d'ACS Groupe** peuvent créer et gérer des événements — et
**non** plusieurs entreprises clientes isolées.

- Il n'y a donc **pas de cloisonnement inter-entreprises** à assurer (un seul
  tenant) → le risque de fuite inter-tenant tel que formulé précédemment (ancien
  R3) **disparaît**.
- La base `Personne` (visiteurs, clé email) est **commune à ACS Groupe**, sans
  problème de partage entre organisations tierces.
- Le champ **« Entité/Entreprise »** du formulaire garde tout son sens : les
  **visiteurs externes** viennent de sociétés variées ; on capture leur entreprise
  d'origine.
- **Q14 tranchée** : **pas de cloisonnement entre organisateurs internes**. Tout
  compte interne ACS Groupe authentifié voit **l'intégralité** des événements et
  présences de l'organisation, quel que soit le créateur (accès partagé complet).

## 3. Périmètre

### Dans le périmètre (décidé)
- **Dashboard organisateur** (authentifié, comptes internes ACS Groupe) : création
  et gestion d'événements, choix du mode QR, consultation/recherche des présences,
  graphiques/statistiques, exports.
- **Page publique d'émargement** (sans compte), mobile-first, accessible par scan
  d'un QR **statique ou tournant** selon l'événement.
- Formulaire visiteur complet (§ 5.1) avec **géolocalisation obligatoire** et
  **signature manuscrite tactile**.
- **Reconnaissance du visiteur récurrent par email** (entité `Personne`).
- **Règle anti-chevauchement** : un individu ne peut pas être présent à deux
  activités simultanées (§ 5.3).
- **Email automatique de récapitulatif** en fin d'événement (§ 5.4).
- **Idempotence** et **dédoublonnage automatique** (§ 5.2, § 5.3).
- Modèle **Personne / Présence** (§ 6).
- Consultation temps quasi réel + **export** (CSV a minima).
- Conformité **protection des données selon le droit ivoirien** (Loi n°2013-450,
  ARTCI) — voir § 8.

### Hors périmètre (décidé)
- Application mobile native ; billetterie/paiement.
- **Valeur légale/probante** de l'émargement (signature = preuve morale/visuelle).
- Multi-tenant SaaS (un seul tenant : ACS Groupe).

## 4. Utilisateurs et parties prenantes

| Acteur | Rôle | Besoins clés |
|---|---|---|
| **Organisateur interne ACS Groupe** | Compte authentifié ; crée/gère événements, affiche le QR, suit/exporte | Rapidité, vue temps réel, recherche efficace, export, statistiques |
| **Visiteur** | Scanne le QR, remplit le formulaire, géolocalise, signe ; reçoit le récap par email | Zéro friction, pas d'app ni compte, mobile, confiance sur ses données |
| **ACS Groupe (responsable de traitement)** | Exploite les présences ; définit les finalités | Fiabilité, exhaustivité, conformité |
| **Autorité de contrôle (ARTCI)** | Cadre légal ivoirien | Conformité Loi n°2013-450 ❓ (à documenter, ne rien inventer) |
| **Équipe projet** | Utilisateur pilote + agents IA | Cadre clair, plan partagé |

## 5. Exigences fonctionnelles

### 5.1 Page publique d'émargement (visiteur, sans compte)
Champs du formulaire (libellés exacts fournis par l'utilisateur) :

| Champ | Obligatoire | Notes |
|---|---|---|
| Nom | ✅ | |
| Prénom(s) | ✅ | |
| Email | ✅ | **Clé de reconnaissance** du visiteur récurrent ; destinataire du récap |
| Numéro de téléphone | ✅ | |
| Entité/Entreprise | ✅ (par défaut) ❓ | **Provenance** du visiteur ; avant Direction ; obligatoire à confirmer (Q12) |
| Direction | ✅ | |
| Service | ❌ (optionnel) | |
| Poste ou Fonction | ✅ | Libellé exact : « Poste ou Fonction » |
| Géolocalisation | ✅ **obligatoire** | **Le formulaire ne peut PAS être validé sans position** (voir F3) |
| Signature manuscrite | ✅ | Pad tactile ; valide l'enregistrement |

> **Géolocalisation strictement obligatoire** : si le navigateur refuse la
> permission, on **informe clairement le visiteur que la position est obligatoire**
> pour valider le formulaire, et on **redemande** la permission. **Aucune
> soumission « sans position », aucun contournement.**

> **Entité/Entreprise** : couvre le cas de visiteurs de structures différentes.
> Rattaché à `Personne` (réutilisé à la reconnaissance par email), **sauf si**
> ressaisie souhaitée à chaque présence (Q13).

- **F1** Scan du QR (**statique ou tournant** selon le mode) → page publique. En
  tournant, le lien porte un **token à durée de vie de 15 s** ; en statique, lien
  stable (imprimable).
- **F2** Email déjà connu → reconnaissance de la `Personne`, message « vous êtes
  reconnu(e) », formulaire réduit à **géolocalisation + signature**.
- **F3** Soumission :
  - **Géolocalisation obligatoire** : bloque la validation tant que la position
    n'est pas obtenue ; message + nouvelle demande de permission si refus.
  - **Mode tournant** : vérification serveur de la validité du token (15 s) ; rejet
    + invitation à rescanner si expiré. **Mode statique** : pas de token (anti-fraude
    = géoloc + fenêtre + anti-doublon).
  - **Anti-chevauchement** (§ 5.3) vérifié.
  - Si tout est valide → création d'une **Présence** (géoloc + signature +
    horodatage serveur) + **notification de confirmation** à l'écran.
- **F4** **Idempotence & anti-doublon** : une soumission répétée (double-clic,
  rescan par erreur) **ne crée jamais de doublon** ; dédoublonnage automatique
  (clé email + événement).
- **F5** Fenêtre d'ouverture/fermeture de l'émargement (refus hors fenêtre).
- **F6** Mentions légales de protection des données (droit ivoirien) sur la page.

### 5.2 Dashboard organisateur (authentifié, comptes internes ACS Groupe)
- **F7** Authentification organisateur (comptes internes). **Accès partagé** :
  tout compte interne voit tous les événements/présences (pas de cloisonnement — Q14).
- **F8** CRUD événement (titre, date/heure, lieu optionnel) + **choix du mode QR
  (`statique` / `tournant`) à la création**.
- **F9** **Génération/affichage du QR selon le mode** :
  - *statique* : QR stable **téléchargeable/imprimable** (posé sur table, sans écran).
  - *tournant* : QR **réaffiché en boucle** (projection), rafraîchi par polling
    (token 15 s, sans websocket). Nécessite un écran physique.
- **F10** Consultation de la liste de présence **temps quasi réel** + compteur.
- **F11** **Graphiques / statistiques** (à préciser avec `ux-designer` — Q17).
- **F12** **Recherche par nom via palette de commande type Cmd+K** (pattern UX
  moderne, pas une simple barre de recherche).
- **F13** **Idempotence** de la plateforme et **dédoublonnage automatique** (aucune
  intervention manuelle de l'organisateur pour gérer les doublons).
- **F14** Export de la liste (CSV a minima).

### 5.3 Règle anti-chevauchement de présence simultanée (nouvelle)
Un même individu **ne doit pas être enregistré présent à deux activités qui ont
lieu en même temps**. Comportement attendu (citation utilisateur) : « quand il
scanne une activité, il doit d'abord sortir de celle-ci pour en scanner une autre ».

> ⚠️ **Ambiguïté de conception à trancher par `senior-fullstack`** (l'utilisateur a
> décrit le **comportement**, pas le **mécanisme**) — voir Q15 :
> - **(a) Règle serveur automatique** basée sur les **fenêtres temporelles** : si
>   la personne a déjà une présence active dans un événement dont l'horaire
>   chevauche celui de l'événement scanné → **rejet avec message clair**.
> - **(b) Action explicite de sortie/checkout** : le visiteur doit **rescanner
>   pour signaler son départ** d'une activité avant de pouvoir en scanner une autre
>   en cours.
>
> Impacte le modèle de données (présence avec/sans état « actif/sorti ») et l'UX.

### 5.4 Email automatique de récapitulatif en fin d'événement (nouvelle)
À la **fin de l'événement** (fin de fenêtre horaire), le système **envoie
automatiquement un email** à **tous les participants** ayant émargé (récapitulatif
de présence).

- Déclenchement **automatique** via les **tâches planifiées cron cPanel**
  (contrainte mutualisé — § 7).
- Nécessite un **envoi d'email depuis Hostinger** : SMTP ou service mail tiers (à
  définir par `senior-fullstack` / `devops-sre`). Laravel Mail + file d'attente en
  base possible, mais **sans worker persistant** → traitement déclenché par cron.
- Contenu exact du récapitulatif à préciser (Q18).

## 6. Modèle de données (cible, à affiner par `senior-fullstack`)

- **Utilisateur interne (organisateur)** : compte authentifié ACS Groupe,
  créateur d'événements. Pas de multi-tenant ; **accès partagé** — tout compte voit
  tous les événements/présences (Q14 tranchée).
- **Événement** : possède une fenêtre d'émargement, un lieu optionnel, et un
  **`mode_qr` (`statique` | `tournant`)** choisi à la création. En mode tournant,
  porte un secret/série de **tokens QR** à durée de vie **15 s**.
- **Token QR** (mode tournant uniquement) : valeur courte durée (15 s) liée à un
  événement, générée/validée côté serveur. **Non applicable en statique.**
- **Personne** : entité **globale (ACS Groupe), unique par email**, portant les
  infos réutilisables (nom, prénoms, téléphone, **entité/entreprise ❓**, direction,
  service, poste). Permet la reconnaissance du récurrent.
- **Présence** : **une par scan/événement** ; rattachée à `Personne` + `Événement` ;
  porte géoloc + signature + horodatage. Peut nécessiter un **état
  (actif/sorti)** selon l'arbitrage anti-chevauchement (§ 5.3, option b).

## 7. Contraintes techniques (verrouillées au J0)

- **Hébergement de production : Hostinger « Premium Web Hosting »** — mutualisé
  cPanel, **PHP + MySQL**, **sans Node.js persistant ni websocket**. Non négociable.
- **Framework : Laravel** (PHP) — **choix verrouillé** (Q10 tranchée).
- **QR tournant** : durée de vie du token = **15 secondes** (Q11 tranchée) ;
  s'applique **uniquement au mode `tournant`**.
- **Temps réel** simulé par **polling** ; **tâches planifiées via cron cPanel**
  (fermeture d'événement, envoi des emails récap, dédoublonnage éventuel).
- **Envoi d'emails** depuis Hostinger (SMTP ou service mail) — à définir ; prévoir
  SPF/DKIM/DMARC pour la délivrabilité.
- **Environnement de dev : MAMP local** (`/Applications/MAMP/htdocs/Presence`,
  PHP 8.5, MySQL) — cohérent avec la cible. `senior-fullstack` doit vérifier la
  compatibilité entre la version PHP disponible sur Hostinger et la version de
  Laravel retenue.
- **HTTPS obligatoire** (API géolocalisation + protection des données).
- **Greenfield** ; projet basé en **Côte d'Ivoire** (droit ivoirien, § 8).

## 8. Protection des données — cadre ivoirien (PAS le RGPD UE)

Cadre de référence : **droit ivoirien**, **Loi n°2013-450 du 19 juin 2013**,
autorité **ARTCI**. Les données collectées (identité, email, téléphone, entreprise,
direction, service, poste, **géolocalisation**, **signature manuscrite**) sont des
données personnelles ; géoloc et signature sont particulièrement sensibles.

> ⚠️ **Conservation indéfinie demandée (Q8)** : l'utilisateur ne veut **pas de
> purge automatique** — conservation **indéfinie**. **Flag pour `security-expert`** :
> la conservation indéfinie de données personnelles (dont géoloc et signature)
> peut **entrer en conflit avec un principe de limitation de la durée de
> conservation** si la Loi n°2013-450 en prévoit un. **À vérifier par recherche
> réelle — ne rien inventer.** Si la loi l'impose, **remonter le conflit à
> l'utilisateur** (ne pas l'ignorer, ne pas trancher à sa place).

> ⚠️ **Ne rien inventer** sur le contenu de la loi : obligations exactes
> (déclaration/autorisation ARTCI, base légale, information, conservation, droits,
> sécurité) à **vérifier** et documenter par `security-expert` / `redacteur-technique`.

Principes de conception par défaut (bonnes pratiques, indépendants du texte) :
minimisation, information claire sur la page publique, droits
d'accès/rectification/effacement, HTTPS, accès restreint, moindre privilège.

## 9. Risques identifiés

| # | Risque | Impact | Prob. | Mitigation |
|---|---|---|---|---|
| R1a | **Fraude en mode QR statique** (QR imprimé photographiable/partageable) | Moyen | Élevée | Compromis assumé : géolocalisation **obligatoire** + fenêtre temporelle + anti-doublon |
| R1b | **Fraude en mode QR tournant** | Faible | Faible | Token **15 s** validé côté serveur à la soumission + géoloc + fenêtre ; impose un écran |
| R2 | **Non-conformité au droit ivoirien** (Loi 2013-450), notamment **conservation indéfinie** | Élevé (légal) | Moyenne | Recherche réelle `security-expert` ; remonter tout conflit de conservation à l'utilisateur |
| R4 | **Contraintes mutualisé** (pas de Node/worker/websocket ; cron cPanel pour emails/fermeture) | Moyen | Élevée | Laravel + polling + cron ; envoi email via cron, pas de worker permanent |
| R5 | **Refus de géolocalisation bloquant l'émargement** (géoloc obligatoire) | Moyen | Élevée | Message clair « position obligatoire » + redemande de permission ; HTTPS ; UX soignée pour limiter l'abandon |
| R6 | **Pic de scans simultanés** (visiteurs illimités) | Moyen | Moyenne | Requêtes légères, index MySQL, contraintes d'unicité ; surveiller limites du mutualisé |
| R7 | **Stockage des signatures** (volume, format, sécurité) | Moyen | Moyenne | Format défini par dev (Q9) ; accès protégé |
| R8 | **Fenêtre de rejeu du token QR** / horloges désynchronisées (tournant) | Faible | Moyenne | Durée courte (15 s) + tolérance maîtrisée ; horodatage serveur faisant foi |
| R10 | **Délivrabilité / échec d'envoi des emails récap** (mutualisé, SPF/DKIM) | Moyen | Moyenne | SMTP configuré (SPF/DKIM/DMARC), file d'attente en base + relance via cron, journalisation |
| R11 | **Anti-chevauchement mal spécifié** (mécanisme a vs b non tranché) | Moyen | Moyenne | Arbitrage `senior-fullstack` (Q15) avant implémentation ; impacte le modèle de données |
| R12 | **Sur-ingénierie** | Moyen | Moyenne | MVP strict, YAGNI, jalons |

> **Ancien R3 (fuite inter-tenant) supprimé** : sans objet en mono-tenant. Résidu
> éventuel = cloisonnement entre organisateurs internes (Q14), de portée bien
> moindre.

## 10. Critères de succès

- Un visiteur émarge (formulaire + géoloc **obligatoire** + signature) en < 30 s
  sur mobile, sans app ni compte ; le récurrent en < 15 s (parcours allégé).
- Un organisateur crée un événement (mode statique **ou** tournant), suit les
  présences en temps quasi réel, recherche par nom (Cmd+K), voit des statistiques
  et exporte.
- Un QR tournant photographié puis rescanné **après 15 s** est **rejeté**.
- **Zéro doublon** malgré double-clic / rescan (idempotence + dédoublonnage auto).
- Un individu ne peut pas être présent à **deux activités simultanées**.
- L'**email récapitulatif** est envoyé automatiquement à tous les participants en
  fin d'événement.
- Cadre ivoirien documenté, conflit de conservation (le cas échéant) remonté, audit
  sécurité passé avant mise en production ; WCAG 2.2 AA sur le parcours visiteur.

## 11. Décisions verrouillées (rappel) & questions résolues

- **Mono-tenant ACS Groupe** ; « multi-organisateur » = comptes internes.
- **Mode QR par événement** (statique/tournant), token tournant **15 s** (Q11).
- **Framework Laravel** (Q10). **Géolocalisation strictement obligatoire** (Q6).
- **Conservation indéfinie** (Q8, avec flag légal). Format signature **délégué au
  dev** (Q9).
- Idempotence + dédoublonnage automatique ; email récap auto ; anti-chevauchement.

## 12. Questions ouvertes restantes (statut J0)

| # | Question | Nature | Qui | Bloque J0 ? |
|---|---|---|---|---|
| Q12 | « Entité/Entreprise » obligatoire ou optionnel ? | Produit | utilisateur | Non (défaut obligatoire) |
| Q13 | Entreprise réutilisée depuis `Personne` ou ressaisie par présence ? | Modèle | fullstack → utilisateur | Non |
| ~~Q14~~ | ✅ **TRANCHÉE** : pas de cloisonnement — tout compte interne voit tous les événements/présences (accès partagé) | Produit/archi | utilisateur | — |
| Q15 | Mécanisme anti-chevauchement : (a) règle serveur sur fenêtres, ou (b) checkout explicite ? | Conception | **senior-fullstack** | Non (arbitrage dev) |
| Q16 | Conformité conservation indéfinie vs Loi 2013-450 | Légal | **security-expert** (recherche réelle) | Non (recherche en cours) |
| Q17 | Statistiques/graphiques du dashboard (lesquels) ? | UX | **ux-designer** → utilisateur | Non |
| Q18 | Contenu exact de l'email récapitulatif | Produit/UX | ux-designer → utilisateur | Non |

> **Conclusion J0** : les décisions structurantes (nature mono-tenant, stack
> Laravel + MySQL + Hostinger, modes QR, géoloc obligatoire, flux principaux,
> nouvelles règles métier) sont **arrêtées**. Les questions restantes sont soit
> **déléguées aux agents spécialistes** (Q15 dev, Q16 sécurité, Q17/Q18 UX), soit
> des **confirmations produit non bloquantes** (Q12, Q13, Q14). → **J0 est
> considéré clos pour lancer `security-expert` et `ux-designer` en parallèle**, à
> condition de faire confirmer Q14 avant de figer le dashboard.
