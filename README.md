# La Roue Ford

Jeu promotionnel « La Roue Ford » : le visiteur s’inscrit, fait tourner une roue
et découvre le cadeau que le serveur lui a attribué.

Application Symfony 6.4 / PHP 8.2, rendu serveur en Twig, JavaScript limité à
l’animation de la roue.

## Parcours

| Étape | Route | Écran |
|---|---|---|
| Accueil | `GET /` | Titre, accroche et bouton **Participer** |
| Inscription | `GET\|POST /inscription` | Formulaire Prénom, Nom, Société ou agence, Adresse e-mail, Téléphone (facultatif) |
| Confirmation | `GET /inscription/confirmation` | « Inscription confirmée ! » + bouton **Faire tourner la roue** |
| Roue | `GET /jeu` | La roue et le bouton **Faire tourner la roue** |
| Tirage | `POST /jeu/tourner` | Réponse JSON : le résultat décidé par le serveur |
| Nouvelle partie | `POST /nouvelle-partie` | Réinitialise la session et renvoie à l’accueil |

L’inscription est obligatoire : `/jeu` et `/jeu/tourner` refusent tout visiteur
sans participant en session.

## Le résultat est décidé côté serveur

C’est la règle centrale de l’application.

* Le navigateur envoie un `POST /jeu/tourner` **sans aucun paramètre** ; tout
  champ qu’il ajouterait serait ignoré.
* Le participant est lu dans la session serveur, jamais dans la requête.
* `App\Service\Game\SpinService` choisit le lot, réserve le stock et enregistre
  le tirage, puis renvoie le résultat.
* `public/assets/js/roue-ford.js` se contente de faire tourner la roue jusqu’au
  secteur du lot reçu.

La requête de tirage est protégée par un jeton CSRF (en-tête `X-CSRF-Token`),
comme le formulaire d’inscription et le bouton « Nouvelle partie ».

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
└── Service/Game/
    ├── GameSession.php                 Suivi du participant en session
    ├── ParticipantRegistrar.php        Inscription
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

Trois garde-fous superposés :

1. `SpinService::spin()` est idempotent : si un tirage existe, il est renvoyé
   tel quel (double-clic, rejeu réseau, rafraîchissement).
2. Un verrou pessimiste sur la ligne du participant sérialise deux requêtes
   réellement simultanées.
3. `spin.participant_id` porte un index unique : la base tranche en dernier
   ressort.

« Nouvelle partie » vide la session et renvoie à l’accueil. Le tirage précédent
reste en base et ne peut être ni rejoué ni modifié : rejouer suppose une
nouvelle inscription, donc un nouveau participant.

## Back-office

Un back-office EasyAdmin est disponible sur `/admin`, réservé à `ROLE_ADMIN`
(`config/packages/security.yaml`) :

* **Lots** (`PrizeCrudController`) — CRUD complet : nom, description, type,
  poids, stock, activation, ordre d’affichage, couleur. Ces champs sont lus
  directement par `WeightedPrizeSelector`/`SpinService` : les modifier ici
  change le comportement du tirage sans toucher au code du jeu.
* **Inscriptions** (`ParticipantCrudController`) — liste en lecture seule des
  participants (nom, e-mail, société, téléphone, date d’inscription, lot
  obtenu). Volontairement sans création/modification/suppression : un
  participant et son tirage forment un historique que `SpinService` garantit
  déjà unique.

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
