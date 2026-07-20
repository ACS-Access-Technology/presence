# Prototype Presence — ACS Groupe

Maquettes HTML autonomes pour **valider le workflow visuellement**. Vanilla JS,
0 dépendance, ouvrables par double-clic (aucun serveur requis).

## Fichiers
- **`participant.html`** — parcours participant complet (mobile-first). États :
  chargement → formulaire complet → participant récurrent (réduit) → confirmation,
  + écran d'erreur « géoloc refusée ». Pad de signature canvas **réel** (ça dessine),
  modal de confirmation avant enregistrement, géoloc **simulée**. Un **panneau de démo**
  (coin haut droit) permet de sauter entre les états.
- **`dashboard.html`** — écran de **projection QR tournant** (vidéoprojecteur) : QR géant,
  compte à rebours 15 s (anneau orange), compteur de présents en direct (polling simulé),
  bouton plein écran.
- **`assets/logo-acs-groupe.png`** — logo de marque.

## Comment tester
Ouvrir `participant.html`. Pour la reconnaissance récurrent en flux naturel :
saisir l'email **`awa@acs.ci`** puis quitter le champ. Pour l'erreur géoloc :
cocher « Simuler refus géoloc » dans le panneau démo, puis « Autoriser ma position ».

## Palette de marque ACS (dérivée du logo — à valider)
On garde la structure et les composants du design system maison ; on ne change que les
valeurs de tokens. **Un seul accent fort par écran** (règle maison).
- **Accent principal** : bleu marine ACS `#1E2A78` (boutons, focus, éléments actifs).
- **Highlight orange** `#F07A13` : usage **rare**, gros éléments seulement (anneau de
  compte à rebours de la projection). Contraste insuffisant pour du texte fin sur blanc.
- **Rouge marque** `#E42313` : réservé à la sémantique **erreur** (respecte « un accent »).
- Neutres/surfaces/sombre : repris du design system maison. Clair + sombre supportés.

## Décisions produit intégrées
- **Entité/Entreprise obligatoire** (Q12 tranchée dans ces maquettes).
- **Géolocalisation strictement obligatoire** : aucun contournement ; en cas de refus,
  écran dédié avec relance + aide pas-à-pas.
- **Comptes organisateurs** créés par un admin (pas d'auto-inscription publique).
- **« Mot de passe oublié » reporté hors MVP** : l'écran de connexion (non inclus ici)
  n'aura pas ce lien au MVP ; réinitialisation gérée par un admin.

## Simulations (à remplacer côté implémentation)
- **Géolocalisation** : simulée (flag JS). En réel : `navigator.geolocation`, HTTPS obligatoire.
- **QR code** : motif de démonstration **non encodé/non scannable**. En réel : QR généré
  **côté PHP** (statique imprimable ou token tournant 15 s validé serveur).
- **Temps quasi réel** : simulé par timers. En réel : **polling** (pas de websocket,
  contrainte hébergement mutualisé).
- **Signature** : capturée au canvas. Format d'export recommandé : PNG (Q9 à confirmer).

## Hors périmètre de ce jet
Écran de connexion, liste des événements, création d'événement, vue détail (liste de
présence + statistiques + export + recherche ⌘K), QR statique imprimable. Specs déjà
décrites côté UX ; à prototyper ensuite si validé.
