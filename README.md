# La Roue Ford

Jeu promotionnel « La Roue Ford » : le visiteur s’inscrit, se présente à
l’accueil où l’équipe l’autorise à jouer depuis le back-office, puis fait
tourner la roue une seule fois et découvre le cadeau que le serveur lui a
attribué.

L’inscription et la roue sont deux interfaces indépendantes : la page
d’inscription ne donne pas accès à la roue, et la roue (écran public, sans
formulaire) n’accueille que les participants autorisés à l’entrée.

Application Symfony 6.4 / PHP 8.2, rendu serveur en Twig, JavaScript limité à
l’animation de la roue.

## Parcours

| Étape | Route | Écran |
|---|---|---|
| Accueil | `GET /` | Titre, accroche et bouton **Participer** |
| Inscription | `GET\|POST /inscription` | Formulaire Prénom, Nom, Société ou agence, Adresse e-mail, Téléphone (facultatif) |
| Confirmation | `GET /inscription/confirmation` | « Inscription confirmée » + invitation à se présenter à l’accueil |
| Accueil (équipe) | `/admin` › Inscriptions | Recherche du visiteur puis **Autoriser à jouer** |
| Roue | `GET /jeu` | Écran public : « En attente du prochain joueur », puis « Bonjour {prénom} » et **Faire tourner la roue** |
| Joueur attendu | `GET /jeu/joueur` | JSON interrogé par la roue toutes les 3 s |
| Tirage | `POST /jeu/tourner` | Réponse JSON : le résultat décidé par le serveur |

Un visiteur non inscrit passe d’abord par `/inscription` (lien « Inscrire un
visiteur » dans le back-office). La roue accueille le dernier participant
autorisé qui n’a pas encore joué ; après le résultat, **Terminer** (ou une
fermeture automatique après 20 s) la remet en attente du joueur suivant. Il
n’y a pas de « Rejouer ».

## Le résultat est décidé côté serveur

C’est la règle centrale de l’application.

* Le navigateur envoie un `POST /jeu/tourner` avec **uniquement** l’identifiant
  du joueur attendu (`participant`) ; tout autre champ serait ignoré.
* Le serveur refuse le tirage si ce participant n’a pas été autorisé depuis le
  back-office ou s’il a déjà joué.
* `App\Service\Game\SpinService` choisit le lot, réserve le stock et enregistre
  le tirage, puis renvoie le résultat.
* `public/assets/js/roue-ford.js` se contente de faire tourner la roue jusqu’au
  secteur du lot reçu.

La requête de tirage est protégée par un jeton CSRF (en-tête `X-CSRF-Token`),
comme le formulaire d’inscription.

## Architecture

```
src/
├── Command/SeedPrizesCommand.php       Dotation par défaut (app:game:seed-prizes)
├── Controller/public/                  Contrôleurs minces (HTTP uniquement)
│   ├── HomeController.php
│   ├── RegistrationController.php
│   └── GameController.php
├── Dto/RegistrationDto.php             Données du formulaire + contraintes de validation
├── Entity/{Participant,Prize,Spin}.php Modèle du jeu
├── Enum/PrizeType.php                  Lot principal / lot de consolation
├── Exception/Game/                     Exceptions métier (messages en français)
├── Form/RegistrationType.php
├── Repository/                         Accès aux données, dont le décrément atomique du stock
├── Service/Registration/
│   └── ParticipantRegistrar.php        Inscription (aucun accès à la roue)
└── Service/Game/
    ├── PlayAuthorization.php           Autorisation de jouer et joueur attendu
    ├── PrizeSelectorInterface.php      Stratégie de tirage (remplaçable via un alias)
    ├── WeightedPrizeSelector.php       Tirage aléatoire pondéré
    ├── RandomNumberGeneratorInterface.php
    ├── SpinService.php                 Logique de jeu
    └── SpinResultPresenter.php         Mise en forme du résultat (JSON et Twig)
```

### Tirage pondéré

Chaque lot porte un `weight`. Le tirage attribue à chaque lot un intervalle de
tickets proportionnel à son poids, puis tire un ticket : un lot de poids 50 sort
cinq fois plus souvent qu’un lot de poids 10. Un lot est écarté du tirage s’il
est inactif, de poids nul ou en rupture de stock.

Pour changer d’algorithme, il suffit de pointer l’alias
`App\Service\Game\PrizeSelectorInterface` (dans `config/services.yaml`) vers une
autre implémentation : la logique de jeu ne change pas.

### Stocks et accès concurrents

`remaining_stock` vaut `null` pour un lot illimité. La réservation passe par un
UPDATE conditionnel exécuté par la base :

```sql
UPDATE prize SET remaining_stock = remaining_stock - 1 WHERE id = :id AND remaining_stock > 0
```

Deux joueurs simultanés ne peuvent donc pas se voir attribuer la même dernière
unité : celui qui perd la course voit son lot écarté et un nouveau tirage est
effectué. Tirage et décrément sont dans la même transaction, un échec ne
consomme aucun stock.

### Un seul tirage par participant

Gagnant ou perdant, chaque participant ne joue qu’une fois :

1. `POST /jeu/tourner` refuse (403) un participant qui a déjà joué ou qui n’a
   pas été autorisé, et le back-office ne propose plus « Autoriser à jouer »
   une fois le tirage enregistré (`PlayAuthorization` le refuse aussi).
2. `SpinService::spin()` est idempotent : si un tirage existe, il est renvoyé
   tel quel (double-clic, rejeu réseau).
3. Un verrou pessimiste sur la ligne du participant sérialise deux requêtes
   réellement simultanées ; le tirage existant est relu sous ce verrou.

`spin.participant_id` n’a pas d’index unique : l’historique antérieur peut
contenir plusieurs tentatives pour un même participant.

## Back-office

Un back-office EasyAdmin est disponible sur `/admin`, réservé à `ROLE_ADMIN`
(`config/packages/security.yaml`) :

* **Lots** (`PrizeCrudController`) — CRUD complet : nom, description, type,
  poids, stock, activation, ordre d’affichage, couleur. Ces champs sont lus
  directement par `WeightedPrizeSelector`/`SpinService` : les modifier ici
  change le comportement du tirage sans toucher au code du jeu.
* **Inscriptions** (`ParticipantCrudController`) — outil de l’accueil :
  recherche par nom, e-mail, société ou téléphone, puis action **Autoriser à
  jouer** (masquée une fois que le participant a joué). Les données restent en
  lecture seule, sans création/modification/suppression : un participant et
  son tirage forment un historique que `SpinService` garantit déjà unique.
* **Inscrire un visiteur** / **Ouvrir la roue** — liens vers les pages
  publiques `/inscription` et `/jeu`.

Créer le premier compte administrateur :

```bash
bin/docker-compose exec php bin/console app:admin:create-super-admin admin@exemple.fr
```

L’e-mail et le mot de passe peuvent aussi être passés en arguments ; sans le
mot de passe, la commande le demande de façon masquée. Elle est idempotente :
la relancer avec le même e-mail met à jour le mot de passe. Le compte reçoit
`ROLE_SUPER_ADMIN`, qui hérite de `ROLE_ADMIN` (`role_hierarchy`), donnant
accès à tout `/admin` — la connexion se fait sur `/admin/connexion`.

## Installation

Le projet tourne dans Docker (`php`, `nginx`, `db` MySQL 8, `phpmyadmin`,
`mailhog`).

```bash
make dcupd                                      # construit et démarre les conteneurs
bin/docker-compose exec php composer install
bin/docker-compose exec php composer db-reset   # base + migrations + lots par défaut
```

L’application est disponible sur http://localhost:3200/.

`composer db-reset` lance `app:game:seed-prizes`, qui crée six lots de
démonstration. La commande est idempotente.

## Tests

```bash
bin/docker-compose exec php composer test-db-reset   # une fois, crée la base de test
bin/docker-compose exec php composer test
```

| Suite | Couvre |
|---|---|
| `tests/Unit` | Tirage pondéré (proportionnalité exacte, bornes, lots épuisés/inactifs/poids nul) et validation du formulaire |
| `tests/Integration` | `SpinService` sur une vraie base : stocks, idempotence, courses, contrainte d’unicité |
| `tests/Functional` | Parcours complet HTTP, messages français, CSRF, impossibilité de forcer un lot |

La source d’aléa est isolée derrière
`App\Service\Game\RandomNumberGeneratorInterface`, ce qui rend les tirages
parfaitement déterministes dans les tests.
