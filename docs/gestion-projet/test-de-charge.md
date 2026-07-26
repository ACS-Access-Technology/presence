# Test de charge — ruée de visiteurs à l'ouverture d'un événement

## Objectif

Simuler une vraie ruée (des dizaines/centaines de visiteurs distincts scannant
le QR au même moment) sur le parcours public d'émargement, pour vérifier que
l'application et l'anti-fraude tiennent sous ce volume — pas juste avec 2-3
lignes de test comme le reste de la suite.

## Bug réel trouvé et corrigé

**Le ticket de scan était déterministe à la seconde près.**
`QrTokenService::issueScanTicket()` calculait `HMAC(event_id|seconde_courante)`
— sans nonce, deux visiteurs scannant le même événement dans la **même
seconde** recevaient **le même ticket**. L'anti-rejeu par ticket
(`attendance-store-ticket`, max 5 usages par ticket sur 5 min) les confondait
alors avec un seul ticket rejoué, et bloquait à tort le 6ᵉ visiteur légitime
d'une même seconde avec un 419 ("Votre session de scan a expiré").

Invisible avec un test à 2-3 lignes (jamais deux scans dans la même seconde) ;
systématique dès qu'on simule un vrai afflux. Corrigé en ajoutant un nonce
aléatoire au payload du ticket (`app/Services/QrTokenService.php`), rendant
chaque ticket unique par émission, indépendamment de la seconde. 381 tests
toujours verts après le fix.

## Découverte annexe : `TrustProxies`

En construisant ce test, découvert que l'app n'avait **aucune configuration
`TrustProxies`** alors que la prod tourne derrière le CDN Hostinger (`hcdn`).
Sans ça, `Request::ip()` renvoyait l'IP du CDN pour tous les visiteurs, ce qui
aurait rendu le rate-limit anti-fraude par IP (`attendance-store`, 10/60s)
global à l'événement au lieu de par visiteur. Corrigé dans `bootstrap/app.php`
(`$middleware->trustProxies(at: '*')`) — voir le commentaire dans ce fichier
pour le compromis sécurité (confiance en tout proxy amont, standard pour les
plateformes dont l'IP du load balancer n'est pas publiée).

## Méthodologie

Outil : [k6](https://k6.io) (`brew install k6`). Script :
`tests/load/attendance-rush.js`.

Chaque itération = **un visiteur distinct** (email + IP simulée uniques via
`X-Forwarded-For`) qui exécute le vrai parcours : `GET /e/{slug}` (récupère
ticket + jeton CSRF) puis `POST /e/{slug}` (émargement complet : identité,
géolocalisation, signature PNG).

Profil de charge : montée à 200 visiteurs virtuels en 20 s, maintien 40 s,
descente 10 s (≈ 11 000 émargements complets sur 70 s).

### Lancer le test

```bash
# 1. Créer un événement de test dédié (jetable — à nettoyer après coup)
php artisan tinker --execute="..."   # voir historique de session pour le gabarit exact

# 2. Serveur avec plusieurs workers PHP (php artisan serve est mono-processus
#    par défaut — non représentatif, fausse les temps de réponse et masque
#    les vrais bugs de concurrence sous une charge réaliste)
PHP_CLI_SERVER_WORKERS=8 php artisan serve --port=8002 --no-reload &

# 3. Lancer k6
k6 run -e BASE_URL=http://127.0.0.1:8002 -e SLUG=<slug-event-test> tests/load/attendance-rush.js

# 4. Nettoyer les données de test (événement + présences + personnes créées)
```

## Résultat final (après correction)

- **100 % des vérifications passent** (0 statut inattendu, 0 erreur 500).
- p95 = 10,3 ms (serveur de dev local, 8 workers PHP — pas représentatif de
  la capacité réelle de l'hébergement mutualisé Hostinger, mais confirme
  l'absence de bug de concurrence côté application).
- Les seuls statuts non-200 restants sont des **429 attendus** (rate-limit
  anti-fraude qui fonctionne comme prévu au-delà de 10 tentatives/60s par
  visiteur).

## Ce que ce test NE couvre PAS encore

- **Capacité réelle de l'hébergement mutualisé Hostinger** : ce test tourne
  en local. `php artisan serve`, même multi-workers, reste un serveur de
  développement — pas Apache/PHP-FPM comme en production. Un test calibré et
  prudent contre la vraie prod reste à faire (discuté avec l'utilisateur,
  pas encore lancé à la date de rédaction).
- **Débit réseau réel d'un vrai parc de smartphones** (latence 3G/4G, pertes
  de paquets) : non simulé.
