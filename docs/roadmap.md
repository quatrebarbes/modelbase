# Roadmap

Dernière mise à jour : 2026-07-24 (Phase 3).

Cette roadmap découle des 4 SFD présentes dans `docs/sfd/` :

1. Architecture générale (EX-101, EX-102)
2. Bases de données (EX-201 à EX-208)
3. Modèles (EX-301 à EX-304)
4. Items (EX-401 à EX-420)

## Architecture technique retenue

- **Backend** : package Laravel 13 (service provider, config publiable, routes API `api/modelbase/...`). Pas de données propres au plug-in à ce stade : les entités manipulées (connexions, modèles Eloquent de l'app hôte, items) sont **introspectées dynamiquement** depuis l'application hôte — connexions via `config/database.php`, modèles via scan des classes Eloquent déclarées par l'app hôte, items/colonnes via `Schema`/`DB` sur la table de chaque modèle — pas des tables métier du plug-in. Conséquence : pas de migrations Eloquent classiques pour Model/Item — la convention « migrations avant models avant controllers » s'applique aux éventuelles tables internes du plug-in (ex. si un jour on stocke des préférences d'affichage), pas à ces entités-là.
- **Frontend** : SPA Nuxt 3, consommant l'API du plug-in en JSON.
- **Environnement de dev/test** : docker-compose local, uniquement pour le développement et les tests d'intégration du plug-in — en production les BDD sont entièrement gérées par l'app hôte, hors périmètre du plug-in. Le compose fournit une app hôte Laravel de démo + au moins deux drivers de BDD (mysql, pgsql) pour tester réellement EX-202/EX-204/EX-301 sur plusieurs moteurs plutôt que de tout mocker.
- **Tests** : Feature test par endpoint API, Unit test par fonction non triviale (introspection de schéma, résolution de FK, etc.), conformément à CLAUDE.md.

## État global

| Phase | Module SFD                    | Statut    |
|-------|-------------------------------|-----------|
| 0     | Socle technique               | ✅ fait    |
| 1     | Architecture générale (accès) | ✅ fait    |
| 2     | Bases de données              | ✅ fait    |
| 3     | Modèles                       | ✅ fait    |
| 4a    | Items — listing & détail      | ⬜ à faire |
| 4b    | Items — création/édition      | ⬜ à faire |
| 4c    | Items — suppression           | ⬜ à faire |
| 5     | Points ouverts / backlog      | ⬜ à faire |

---

## Phase 0 — Socle technique

Prérequis à tout le reste, ne couvre pas d'exigence directement.

- [x] Squelette package Laravel (composer.json, ServiceProvider, autoload PSR-4) — package `quatrebarbes/modelbase`, namespace `Quatrebarbes\Modelbase\`, provider auto-découvert (`extra.laravel.providers`)
- [x] Fichier de config publiable (ex. connexions exclues) — `config/modelbase.php` (`route_prefix`, `excluded_connections`), publiable via `vendor:publish --tag=modelbase-config` — la clé `technical_tables` initialement prévue a été retirée : depuis le passage à un listing par modèles Eloquent déclarés (module 3), l'exclusion des tables techniques est un effet de bord naturel de la découverte (cf. Phase 3)
- [x] Squelette Nuxt 3 (structure pages/composants, client API) — `frontend/` (Nuxt 3.21, layout classique `pages/`/`components/`/`composables/`), `useApiClient()` pointant sur `runtimeConfig.public.apiBase` (`/modelbase/api` par défaut, aligné sur EX-104/105)
- [x] docker-compose (dev/test uniquement, pas de prod) : app hôte Laravel de démo + mysql + pgsql, seed de données de démo — `demo/` (host Laravel 13 requérant le plug-in via repository `path`), `docker-compose.yml` (services `app`/`mysql`/`pgsql`, ports hôte 8000/33061/54321 pour éviter les conflits avec d'autres projets), seed : `categories`→`products` sur mysql, `authors`→`articles` (avec JSON et FK) sur pgsql
- [x] Pipeline de test (Pest/PHPUnit) branché en CI locale — `orchestra/testbench`, `tests/TestCase.php`, `vendor/bin/phpunit` fonctionnel (0 test pour l'instant : le test de vérification `PingTest`/route `/ping` a été retiré une fois le câblage prouvé, cf. ci-dessous)

Points d'attention pour la suite :
- `migrate:fresh` ne réinitialise que la connexion par défaut (mysql) ; l'entrypoint docker (`docker/app-entrypoint.sh`) fait un `db:wipe --database=pgsql` explicite avant, sinon les tables créées via `Schema::connection('pgsql')` (authors/articles) entrent en conflit au redémarrage suivant.
- Les fichiers édités depuis Windows perdent leur bit exécutable (constaté sur `app-entrypoint.sh`) — le compose invoque donc le script via `sh` plutôt que de compter sur `+x`.
- `routes/api.php` ne contient qu'un groupe vide (prêt pour la Phase 1) : la route `/ping` ayant servi à valider le câblage (provider → routes → préfixe configurable → app de démo) a été retirée, n'étant couverte par aucune exigence SFD et incompatible avec le futur middleware d'auth global (EX-101/EX-103) sans cas particulier.

## Phase 1 — Architecture générale (module 1)

Règles transversales, à poser avant les modules 2-4 car elles conditionnent l'accès à toutes les routes.

- [x] Middleware d'authentification appliqué à toutes les routes du plug-in (EX-101) — s'appuie sur le guard d'auth de l'app hôte, pas de rôle spécifique au plug-in — `Quatrebarbes\Modelbase\Http\Middleware\Authenticate`, appliqué au groupe `routes/api.php` ; vérifie `Auth::guard(config('modelbase.guard'))->check()`, guard configurable (`modelbase.guard`, défaut `null` = guard par défaut de l'app hôte), sans aucune autre condition (pas de rôle propre au plug-in)
- [x] Vérification que l'accès à un niveau de navigation ne dépend que de la disponibilité du parent, jamais d'un droit utilisateur (EX-102) — test Feature dédié — le middleware ne fait qu'une vérification d'authentification, aucun gate/policy basé sur un droit utilisateur ; testé en Phase 1 par l'égalité d'accès entre deux utilisateurs quelconques (la dépendance à la disponibilité du parent sera testée concrètement modules 2-4, une fois les routes hiérarchiques en place)
- [x] Réponse 401 JSON sans redirection vers une page de connexion, y compris requête non-JSON (EX-103) — le middleware répond directement `response()->json(..., 401)` plutôt que de lever une `AuthenticationException`, ce qui évite toute dépendance au comportement de redirection du handler d'exceptions de l'app hôte
- Tests Feature : accès refusé si non authentifié (401, pas de header `Location`), accès autorisé sans condition de rôle une fois authentifié — `tests/Feature/AuthenticationTest.php`, via une route de sonde protégée par le même middleware (le groupe `routes/api.php` reste vide tant qu'aucun endpoint des modules 2-4 n'existe)

Points d'attention pour la suite :
- `tests/TestCase.php` charge désormais les migrations par défaut de Testbench (`loadLaravelMigrations()`, table `users` notamment) à chaque test, nécessaire pour authentifier un utilisateur en test Feature.
- Le PHP CLI local (WSL) ne dispose d'aucun driver PDO (ni sqlite, ni mysql, ni pgsql) — `composer test` tel que documenté dans le README ne peut donc pas encore tourner directement sur cette machine. Validé ponctuellement via un conteneur `php:8.3-cli` jetable (qui embarque `pdo_sqlite`, utilisé par défaut par Testbench). À corriger côté environnement de dev (ex. `sudo apt install php8.3-sqlite3`) plutôt que côté repo.

## Phase 2 — Bases de données (module 2)

- [x] Endpoint `GET /connections` listant les connexions de `config/database.php` (EX-201) — `Quatrebarbes\Modelbase\Http\Controllers\ConnectionController@index`, route nommée `modelbase.api.connections.index`
- [x] Pour chaque connexion : nom, driver, statut, nombre de modèles (EX-202) — exclusion des infos sensibles host/port/identifiants (EX-203) — `Support\ConnectionRepository` n'expose que `name`/`driver`/`status`/`model_count`, jamais la config brute de la connexion
- [x] Détection de disponibilité d'une connexion (tentative de connexion à chaud, sans cache) (EX-204, EX-208) — `Support\ConnectionAvailability` : `DB::purge()` avant/après un `DB::connection($name)->getPdo()` en try/catch, pour ne jamais réutiliser un statut déjà résolu plus tôt dans la requête
- [x] Comptage des modèles limité aux connexions disponibles (EX-205) — `model_count` vaut `null` (non calculé) pour une connexion indisponible ; le comptage s'appuie sur `Support\EloquentModelFinder` (scan de `app/Models`, cf. point d'attention ci-dessous)
- [x] Blocage de la navigation vers une connexion indisponible côté API (EX-206) — `Http\Middleware\EnsureConnectionIsNavigable` : 404 si connexion inconnue/exclue, 409 si connexion configurée mais injoignable ; middleware prêt à être appliqué aux routes imbriquées `/connections/{connection}/...` des modules 3-4, testé dès maintenant via une route sonde (même approche que `Authenticate` en Phase 1)
- [x] Front Nuxt : liste des connexions, état visuel disponible/indisponible, navigation vers module 3 (EX-207) — `frontend/pages/index.vue` + `frontend/components/ConnectionList.vue` (remplace le squelette `ApiStatus.vue` de la Phase 0, supprimé) ; lien de navigation uniquement sur les connexions disponibles, vers `/connections/{name}` (page à créer en Phase 3)
- Tests Feature : `tests/Feature/ConnectionListingTest.php` (listing, masquage des infos sensibles, connexion injoignable simulée — hôte local sur un port fermé plutôt qu'un hôte non routable, pour un échec de connexion immédiat — recalcul à chaque appel sans cache), `tests/Feature/ConnectionNavigabilityTest.php` (sonde `EnsureConnectionIsNavigable`)
- Test Unit : `tests/Unit/EloquentModelFinderTest.php` (découverte des modèles concrets, exclusion des classes abstraites/non-Eloquent, filtrage par connexion, répertoire `app/Models` absent)

Points d'attention pour la suite :
- **Mécanisme de découverte des modèles introduit en avance de phase** : `Support\EloquentModelFinder` (scan de `app/Models`, filtrage par connexion) a dû être construit dès la Phase 2 pour permettre le comptage de modèles (EX-202/EX-205), alors qu'il est nominalement prévu en Phase 3 (EX-301). La Phase 3 réutilisera cette même classe pour le listing détaillé (nom, colonnes, items) plutôt que d'en récrire une — seul `EloquentModelFinder::all()`/`forConnection()` est à étendre si besoin (ex. gestion des sous-répertoires, classmap composer en complément du scan de répertoire).
- **Bug corrigé, transverse à toutes les phases** : les routes du plug-in (`routes/api.php`) n'étaient rattachées à aucun groupe de middleware Laravel — sans le groupe `web`, aucune session n'était démarrée et le guard d'auth de l'app hôte (EX-101, session-based par défaut) ne voyait donc jamais un utilisateur comme connecté lors d'un vrai appel HTTP, alors que les tests Feature passaient malgré tout car `actingAs()` contourne le mécanisme de session. Corrigé en ajoutant `'web'` au groupe de middleware ; `tests/TestCase.php` fixe désormais une `app.key` de test (requise par `EncryptCookies`, membre du groupe `web`) pour que les futurs tests Feature de toutes les phases fonctionnent avec ce groupe. Point de vigilance pour la Phase 4b : le groupe `web` inclut la vérification CSRF, à prendre en compte côté front (jeton `XSRF-TOKEN`) pour les endpoints de mutation (POST/PATCH/DELETE).
- Vérifié manuellement contre l'environnement `docker-compose` réel (pas seulement en tests unitaires/Feature) : `GET /connections` reflète correctement mysql (3 modèles : `Category`, `Product`, `User`), pgsql (2 modèles : `Author`, `Article`), mariadb (disponible, alias du même serveur mysql via les mêmes variables d'env `DB_HOST`/`DB_PORT`), sqlsrv (indisponible, aucun serveur), et sqlite (indisponible dans l'environnement de démo — `DB_DATABASE=demo`, définie pour mysql dans `demo/.env`, écrase par effet de bord le chemin par défaut du fichier sqlite ; comportement correctement détecté comme indisponible, non bloquant car aucun modèle de démo n'utilise cette connexion, mais `demo/.env` mériterait un nettoyage si sqlite devait un jour servir à la démo).

## Phase 3 — Modèles (module 3)

- [x] Endpoint `GET /connections/{connection}/models` listant les modèles Eloquent déclarés dans l'application hôte qui utilisent cette connexion (EX-301) — `Http\Controllers\ModelController@index`, route nommée `modelbase.api.connections.models.index`, nichée sous `/connections/{connection}` avec le middleware `EnsureConnectionIsNavigable` (EX-206) réellement appliqué pour la première fois (jusqu'ici seulement exercé via une route sonde en Phase 2)
- [x] Mécanisme de découverte des modèles Eloquent déclarés (scan des classes de l'app hôte, ex. via composer classmap ou répertoire `app/Models`, filtrage sur la connexion utilisée) — Unit test dédié — réutilise tel quel `Support\EloquentModelFinder` construit en avance de phase (Phase 2) ; aucune extension nécessaire
- [x] Pour chaque modèle : nom, nombre d'items, nombre de colonnes (EX-302) — `Support\ModelRepository` : nom via `class_basename`, `item_count` via `Connection::table($table)->count()`, `column_count` via `Connection::getSchemaBuilder()->getColumnListing($table)`
- [x] Front Nuxt : navigation modèle → items (EX-303) — `frontend/pages/connections/[connection]/index.vue` + `frontend/components/ModelList.vue` ; lien `NuxtLink` vers `/connections/{connection}/models/{model}` (page items à créer en Phase 4a)
- [x] Filtre par nom côté listing (EX-304), côté front et/ou query param API — implémenté côté API (query param `search`, insensible à la casse, sous-chaîne) dans `ModelRepository::forConnection()` ; le front relance la requête via `useAsyncData` avec `watch` sur un champ de recherche
- [x] Gestion du cas « aucun modèle éligible » (message, pas d'erreur) — limite documentée — API : tableau `data` vide, 200 ; front : message dédié dans `ModelList.vue`
- [x] Gestion du cas « plusieurs modèles pointant vers la même table » (entrées distinctes) — limite documentée — `ModelRepository` construit une entrée par classe Eloquent, jamais par table ; testé explicitement
- Tests Feature : `tests/Feature/ModelListingTest.php` (listing filtré par connexion, comptage colonnes/items, filtre par nom, connexion sans modèle, doublon de table, connexion indisponible/inconnue via la vraie route)
- Test Unit : `tests/Unit/ModelRepositoryTest.php` (description modèle → table/item_count/column_count, filtre par nom insensible à la casse, recherche vide non filtrante)

Points d'attention pour la suite :
- **Piège sqlite `:memory:` + `EnsureConnectionIsNavigable`** : ce middleware purge la connexion (`DB::purge`, cf. `ConnectionAvailability`, EX-204/EX-208) avant de laisser passer la requête. Sur une connexion sqlite `:memory:`, une purge reconnecte à une base en mémoire *vierge* — toute table créée en amont dans un test Feature disparaît donc dès que la requête passe par une route protégée par ce middleware. `ModelListingTest` utilise un fichier sqlite temporaire sur disque (`tempnam()`) plutôt que `:memory:` pour la connexion `primary` afin d'éviter ce piège ; à reproduire pour tout futur test Feature du module 4 qui interroge réellement les données d'un modèle (les tests de la Phase 2 n'y étaient pas exposés, `model_count` ne faisant qu'un décompte de classes, sans requête SQL).
- **Collision de classes PHP entre fichiers de test** : les tests qui déclarent dynamiquement des classes Eloquent factices dans `app/Models` (via `File::put` + `require`/`require_once`) doivent utiliser des noms de classe uniques *à l'échelle de toute la suite* (pas seulement du fichier de test), PHPUnit exécutant tous les tests dans le même process sans isolation. `require_once` protège en plus contre la redéclaration au sein d'un même fichier de test (un `setUp()` par méthode de test, donc plusieurs écritures/inclusions du même chemin de fichier).
- Le point ouvert EX-402 (colonnes « principales » du listing d'items, cf. Phase 5) reste entier pour la Phase 4a : ce module 3 n'affiche que des compteurs (items/colonnes), pas les colonnes elles-mêmes.
- **Bug corrigé, transverse à toutes les pages front (introduit en Phase 2, révélé en testant manuellement la Phase 3)** : `useApiClient()` (`frontend/composables/useApiClient.ts`) ne transmettait pas le cookie de session lors du rendu SSR — Nuxt exécute l'appel `$fetch` côté serveur Node lors du SSR, sans reprendre automatiquement les en-têtes de la requête entrante. Résultat : la page se rendait toujours comme si l'utilisateur n'était pas connecté (401 côté SSR), quel que soit l'état réel de sa session. Corrigé en passant `useRequestHeaders(['cookie'])` dans les options du `$fetch` uniquement côté serveur (`import.meta.server`).
- **Test manuel de bout en bout** : l'app hôte de démo (`demo/`) n'a aucun scaffolding d'authentification (pas de Breeze/Fortify/Jetstream) ni de route `/login` — une route `GET /dev-login` dev-only a été ajoutée à `demo/routes/web.php` (active seulement si `app()->environment('local')`) pour authentifier l'utilisateur de démo seedé, à visiter une fois dans le navigateur avant d'utiliser le front.
- **Le front Nuxt tourne dans son propre conteneur** (`docker/frontend.Dockerfile`, service `frontend` du `docker-compose.yml`, port `3000`), au même titre que l'app de démo — plus de process Node lancé à la main sur l'hôte/WSL. Il proxifie `/modelbase/api/**` vers le conteneur `app` (route Nitro `routeRules` dans `frontend/nuxt.config.ts`, cible `MODELBASE_API_ORIGIN=http://app:8000` fournie par docker-compose, `http://localhost:8000` par défaut si lancé hors Docker) pour rester same-origin côté navigateur et éviter toute configuration CORS/cookies cross-site. `node_modules` est isolé dans un volume nommé (`frontend_node_modules`) plutôt que bind-monté depuis l'hôte, pour éviter tout conflit de plateforme avec des binaires natifs (esbuild, etc.) déjà installés côté hôte.
- **Prérequis Node pour le CLI Nuxt** : le Node système par défaut de l'environnement WSL utilisé pour le développement (`v18.19.1`) est trop ancien pour le CLI Nuxt (`SyntaxError` sur `node:util`'s `styleText`, requis en interne par `@clack/core`) — d'où le choix de `node:22-slim` pour l'image du conteneur `frontend`. Pertinent uniquement si le front est lancé hors Docker (cf. README) ; le conteneur n'y est pas exposé.

## Phase 4a — Items : listing & consultation (module 4, partie 1)

- [ ] Introspection des colonnes de la table d'un modèle (nom, type, FK) → mapping vers `ColumnType` (EX-407 en prépa)
- [ ] Endpoint `GET /connections/{c}/models/{m}/items` paginé (EX-401, EX-403)
- [ ] Sélection des colonnes « principales » pour l'aperçu du listing (EX-402) — **point ouvert, cf. Phase 5** — afficher toutes les colonnes en attendant que le point soit tranché
- [ ] Gestion modèle vide (EX-404)
- [ ] Endpoint `GET /connections/{c}/models/{m}/items/{id}` détail complet (EX-405, EX-406)
- [ ] Rendu par type de colonne côté front, y compris JSON (EX-407)
- [ ] Résolution des FK en lien de navigation vers l'item référencé (EX-408)
- [ ] Distinction visuelle valeur nulle vs chaîne vide (EX-409)
- [ ] Gestion FK cassée (item référencé supprimé/inexistant) avec indicateur dédié (EX-410)
- [ ] Navigation retour détail → listing (EX-411)
- Tests Feature : pagination, détail, FK valide/cassée, valeur nulle, modèle vide
- Tests Unit : mapping type colonne → rendu, résolution FK

## Phase 4b — Items : création & modification (module 4, partie 2)

- [ ] Endpoint `POST /connections/{c}/models/{m}/items` (EX-412)
- [ ] Endpoint `PATCH/PUT .../items/{id}` (EX-413)
- [ ] Formulaire front adapté par type (texte, numérique, date, checkbox, éditeur JSON) (EX-414)
- [ ] Sélecteur d'item existant pour les colonnes FK (EX-415)
- [ ] Colonnes techniques (PK, timestamps) en lecture seule dans le formulaire (EX-416)
- [ ] Remontée des erreurs de validation natives de la colonne (obligatoire, unicité, format) sans dupliquer ces règles côté plug-in (EX-417)
- Tests Feature : création, modification, erreurs de validation propagées, champs techniques non modifiables

## Phase 4c — Items : suppression (module 4, partie 3)

- [ ] Endpoint `DELETE .../items/{id}` (EX-418)
- [ ] Confirmation obligatoire côté front avant suppression (EX-419)
- [ ] Gestion erreur d'intégrité référentielle (FK entrante) : affichage de l'erreur BDD après confirmation, pas de suppression forcée (EX-420)
- Tests Feature : suppression simple, suppression bloquée par contrainte FK

## Phase 5 — Points ouverts / hors périmètre

À trancher avec le métier avant ou pendant la Phase 4a/3 :

- Choix des colonnes « principales » affichées dans le listing des items (limite Module 4) — proposition à valider : premières colonnes non-FK/non-JSON déclarées, configurable par modèle
- Consultation de la structure d'une table (colonnes/types) indépendamment de la navigation vers ses items — explicitement hors module 3
- Modification/suppression en masse de plusieurs items — explicitement hors module 4

Ces points ne bloquent pas le développement mais doivent revenir sous forme d'exigences SFD complémentaires si le besoin se confirme (cf. skill `ba`).
