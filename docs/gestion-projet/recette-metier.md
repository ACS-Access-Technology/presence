# Recette métier — Presence

> Document de validation métier, en langage non technique. Chaque règle listée
> ci-dessous est une **preuve automatisée** (test exécuté à chaque commit et
> avant chaque déploiement, voir `.github/workflows/ci-cd.yml`) : si la règle
> est violée, le déploiement est bloqué. Ce document ne remplace pas les tests —
> il les rend lisibles et validables par un interlocuteur métier non-développeur.
>
> Pour rejouer la preuve : `composer test` (SQLite en mémoire, aucun risque pour
> les données réelles). Un ✅ signifie « couvert par un test automatisé
> aujourd'hui » — pas « vérifié manuellement une fois ».
>
> **Rédacteur** : agent `qa-testeur` / synthèse Claude Code
> **Date** : 2026-07-26

---

## 1. Émargement public (visiteur, sans compte)

| Règle métier | Preuve |
|---|---|
| Un visiteur émarge sans créer de compte, juste avec son email | `tests/Feature/PublicAttendanceTest.php` |
| Le même email ne peut jamais créer deux présences sur le même événement (anti-doublon), même avec une casse ou des espaces différents (`Awa.Kone@Exemple.CI` = `awa.kone@exemple.ci`) | `tests/Feature/AttendanceServiceTest.php` |
| Un visiteur déjà présent à un autre événement en cours doit confirmer son départ avant de s'enregistrer ailleurs (anti-chevauchement) | `tests/Feature/AttendanceServiceTest.php` |
| Le QR tournant change toutes les 15 secondes ; un QR figé (fenêtre expirée) est refusé | `tests/Unit/QrTokenServiceTest.php` |
| Un QR statique exige la géolocalisation (le QR fixe est photographiable, la géoloc est la seule barrière anti-fraude à distance) | `tests/Feature/PublicAttendanceTest.php`, audit sécurité |
| Après la clôture officielle d'un événement, si le délai de grâce est activé, l'émargement reste possible 15 minutes de plus ; passé ce délai, refus net | `tests/Feature/GraceCheckInPublicTest.php`, `tests/Feature/GraceCheckInTest.php` |
| Une clôture manuelle par l'organisateur ferme l'émargement immédiatement, même pendant le délai de grâce | `tests/Feature/GraceCheckInPublicTest.php` |
| Un événement annulé ne peut plus recevoir d'émargement | `tests/Feature/GraceCheckInPublicTest.php` |
| Aucun fichier (signature, photo, document) n'est jamais accessible par une URL publique devinable — tout passe par une route authentifiée | `tests/Feature/EventReportTest.php`, `tests/Feature/PublicAttendanceTest.php` |

## 2. Gestion d'un événement (organisateur / admin)

| Règle métier | Preuve |
|---|---|
| Le statut affiché (à venir / en cours / clos / annulé) se calcule toujours depuis l'horloge — jamais une valeur figée qui pourrait se désynchroniser | `tests/Unit/EventStatusBoundaryTest.php` (transitions à la seconde exacte) |
| Une annulation prime toujours sur les autres statuts, même après l'heure de fin | `tests/Unit/EventStatusBoundaryTest.php` |
| Reporter un événement conserve l'historique des présences déjà enregistrées et notifie les participants du nouveau créneau | `tests/Feature/EventRescheduleNotificationTest.php`, `tests/Feature/EventRescheduleTransferInteractionTest.php` |
| Reporter un événement ne change jamais le secret du QR : les QR déjà imprimés/affichés restent valides | `tests/Feature/EventRescheduleTransferInteractionTest.php` |
| Transférer un événement vers une autre filiale réaffecte son type dans la filiale cible et conserve l'historique des présences | `tests/Feature/EventTransferTest.php`, `tests/Feature/EventRescheduleTransferInteractionTest.php` |
| Le mode du QR (statique/tournant) se verrouille dès la première présence enregistrée — plus modifiable ensuite | `tests/Feature/AttendanceServiceTest.php` |
| À l'heure de fin, la clôture et l'envoi de l'email récapitulatif se font automatiquement (cron), sans double envoi même en cas de relance | `tests/Feature/CloseDueEventsTest.php`, `tests/Feature/CloseDueEventsMultiFilialeTest.php` |
| Le cron de clôture traite bien toutes les filiales en une passe, même si un contexte de filiale était actif au moment de son exécution | `tests/Feature/CloseDueEventsMultiFilialeTest.php` |
| Si l'envoi d'un email récapitulatif échoue (panne réseau...), la présence n'est jamais marquée « envoyée » à tort — une relance renvoie une seule fois | `tests/Feature/SendAttendanceConfirmationFailureTest.php` |
| Un événement annulé passé la veille disparaît de la vue « Tous » du tableau de bord (reste visible dans « Annulés ») pour ne pas polluer la liste | `tests/Feature/EventDashboardTest.php` |

## 3. Documentation d'une activité (photos, documents, Portfolio)

| Règle métier | Preuve |
|---|---|
| Photos et documents ne sont ajoutables qu'une fois l'événement commencé | `tests/Feature/EventReportTest.php` |
| Le Portfolio n'affiche que les activités ayant au moins une photo, un document ou un compte-rendu — jamais une activité vide | `tests/Feature/PortfolioTest.php` |
| Le clic sur une activité **avec photos** ouvre sa galerie ; une activité **sans photo** (documentée par texte/document seul) mène directement à son contenu — jamais une galerie vide trompeuse | `tests/Feature/PortfolioTest.php` (`test_une_carte_sans_photo_pointe_vers_le_contenu_pas_vers_la_galerie`) |
| Les photos d'une galerie s'affichent toujours dans l'ordre choisi (position), quel que soit l'ordre d'ajout | `tests/Feature/PortfolioTest.php` (`test_show_ordonne_les_photos_par_position`) |
| Un document conserve son nom d'origine tel quel, y compris avec accents ou caractères spéciaux | `tests/Feature/EventReportTest.php` (`test_upload_document_conserve_un_nom_unicode`) |
| Chaque type de fichier (PDF, Word, Excel, PowerPoint, CSV, texte) est reconnaissable visuellement par une icône dédiée | `tests/Feature/EventReportTest.php` (`test_upload_document_conserve_lextension_pour_le_badge_visuel`, vérification visuelle live) |

## 4. Multi-filiale (holding ACS Groupe)

| Règle métier | Preuve |
|---|---|
| Un Organisateur ou un Admin filiale ne voit et ne peut modifier **que** les données de sa propre filiale — jamais celles d'une autre, même en devinant une URL | `tests/Feature/FilialeIsolationScopingTest.php`, `tests/Feature/FilialeIsolationHardeningTest.php` |
| Le SuperAdmin peut consulter toutes les filiales, ou se verrouiller sur une seule ; les deux modes sont strictement distincts et testés séparément | `tests/Feature/FilialeIsolationHardeningTest.php` |
| Un compte administrateur sans filiale assignée (incohérence de données) ne voit et ne peut rien faire — jamais un repli silencieux vers « tout voir » (RME-7) | `tests/Feature/SecurityGateAuditTest.php`, `tests/Feature/FilialeIsolationHardeningTest.php` |
| La page publique d'émargement (`/e/{slug}`) reste accessible à tout visiteur, indépendamment de tout contexte de filiale en session côté admin | `tests/Feature/FilialeIsolationHardeningTest.php` |
| Le référentiel « Personnel » (participants) reste unique à l'échelle de la holding — un visiteur n'est jamais dédoublonné entre filiales | `tests/Feature/MultiFilialeAdminTest.php` |

## 5. Comptes, paramètres et branding

| Règle métier | Preuve |
|---|---|
| Seul un administrateur crée les comptes — pas d'auto-inscription, pas de « mot de passe oublié » en libre-service | `tests/Feature/SettingsTest.php` |
| Un compte jamais connecté depuis son invitation est distingué visuellement d'un compte actif ou désactivé | `tests/Feature/SettingsTest.php` (`test_settings_distingue_jamais_connecte_et_deja_connecte`) |
| Une filiale peut hériter du branding de la holding, ou définir le sien — le choix est explicite et réversible | `tests/Feature/SettingsTest.php` (`test_admin_filiale_active_lheritage_du_branding_holding`) |
| Réassigner un compte à une filiale refuse une filiale inexistante ou désactivée | `tests/Feature/SettingsTest.php` |

## 6. Performance (garde-fous, pas un test de charge)

| Règle métier | Preuve |
|---|---|
| Les listes (événements, portfolio, annuaire participants) répondent avec un nombre de requêtes constant, qu'il y ait 3 ou 25 lignes affichées — pas de ralentissement caché qui n'apparaîtrait qu'en production avec du volume réel | `tests/Feature/PerformanceQueryBudgetTest.php` |

## 7. Après déploiement (smoke test)

| Règle métier | Preuve |
|---|---|
| Après chaque déploiement en production, le site est vérifié automatiquement comme étant réellement accessible (`/up`) — le déploiement échoue visiblement sinon, au lieu de rester silencieusement cassé | `.github/workflows/ci-cd.yml` (étape « Smoke test ») |

---

## 8. Parcours navigateur réels (End-to-End)

| Règle métier | Preuve |
|---|---|
| Un administrateur se connecte via le vrai formulaire (bon mot de passe → tableau de bord ; mauvais mot de passe → message d'erreur, reste sur la page) | `tests/Browser/AdminLoginTest.php` |
| La galerie photo du Portfolio s'ouvre, navigue et se ferme réellement au clic dans un vrai navigateur | `tests/Browser/PortfolioGalleryBrowserTest.php` |

Voir `docs/gestion-projet/tests-e2e-dusk.md` pour lancer ces tests en local
(config à part, pas encore dans le pipeline CI — voir ce document pour le
pourquoi).

## Ce que ce document NE couvre PAS encore

- **Parcours public d'émargement en E2E** (QR → géolocalisation → signature) :
  nécessiterait de simuler la géolocalisation navigateur, plus complexe à
  fiabiliser — non fait pour l'instant (voir `tests-e2e-dusk.md`).
- **Compatibilité multi-navigateurs** (Chrome/Firefox/Safari) : non testée
  automatiquement.
- **Test de charge réel** (montée en charge, temps de réponse sous trafic) :
  hors périmètre — nécessiterait un environnement dédié, jamais la production.

Ces manques sont documentés volontairement plutôt que dissimulés : ce tableau
reflète ce qui est réellement garanti aujourd'hui, pas ce qui est souhaité.
