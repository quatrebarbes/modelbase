# Roadmap

Dernière mise à jour : 2026-08-19 — condensation de ce document (cf. note ci-dessous) et ajout rétroactif d'EX-476, jusque-là non consignée.

> **Note sur cette version** : ce document a été fortement condensé le 2026-08-19 (697 → ~250 lignes). L'historique narratif complet (déroulé détaillé de chaque investigation, vérifications manuelles pas à pas, corrections d'erreurs de rédaction antérieures de ce même document) reste consultable via `git log -p -- docs/roadmap.md` ; il n'apportait plus d'information utile à la poursuite du développement une fois les phases closes. Seuls sont conservés : les décisions d'architecture encore actives, les limites documentées dans la SFD, les bugs corrigés dont la cause reste instructive (garde-fous de sécurité notamment), et les points d'attention encore valables aujourd'hui.

**Bug corrigé le 2026-08-17 (mémoire épuisée à la lecture du listing des modèles d'une connexion réelle)** — cause : `ColumnIntrospector::relationForeignKeys()` (EX-423) et `RelationIntrospector::relationsOf()` (EX-307/EX-425) invoquaient à l'aveugle toute méthode publique sans paramètre requis du modèle hôte pour tester si elle renvoie une relation Eloquent ; une méthode exécutant elle-même une lecture non bornée (`return $this->hasMany(...)->get();`) était donc invoquée et chargeait tout son résultat en mémoire avant d'être écartée. Corrigé en deux temps : (1) filtre par type de retour déclaré, qui exclut sans invocation une méthode dont le type ne peut pas être une `Relation` ; (2) nouveau point d'invocation unique `RelationMethodGuard::invoke()`, qui exécute l'appel à l'intérieur d'un `Connection::pretend()` — toute requête réellement déclenchée est alors court-circuitée par Laravel lui-même. Limite documentée : ne protège que la connexion de l'instance elle-même, pas un effet de bord PHP sans requête SQL. 9 tests ajoutés (`RelationMethodGuardTest`). Suite complète (327 tests) au vert.

Cette roadmap découle des 4 SFD de `docs/sfd/` :

1. Architecture générale (EX-101 à EX-119)
2. Bases de données (EX-201 à EX-213, EX-215)
3. Modèles (EX-301 à EX-316)
4. Items (EX-401 à EX-456, EX-463 à EX-476)

**Toutes les exigences EX-101 à EX-476 sont développées.** Audit de cohérence SFD ↔ code réalisé le 2026-08-10 (aucun écart trouvé à cette date).

## Points d'attention transverses, toujours valables

- **Build front** (`./docker/build-front-package.sh`) : ne fonctionne pas tel quel depuis un shell Windows/Git Bash pointant sur un chemin réseau `\\wsl.localhost\...` (résolution de chemin UNC incompatible avec `docker run -v`/`-w`). Contournement fiable : lancer le script depuis l'intérieur de la distribution WSL (`wsl.exe -d Ubuntu -- bash -lc 'sh ./docker/build-front-package.sh'`), ou à défaut `nuxt generate` directement dans le conteneur `frontend` déjà démarré puis `docker compose restart frontend`. **À relancer et committer dans `resources/dist/modelbase/` après toute modification de `frontend/`** (cf. CLAUDE.md).
- **Pas de harnais de test automatisé pour le front** (aucun composant Vue couvert par des tests unitaires/composants, par convention de ce projet — seuls les endpoints/fonctions backend le sont). Vérification manuelle systématique contre l'environnement `docker-compose` réel (mysql + pgsql). Un outil d'automatisation navigateur (Playwright/Chromium) est disponible depuis la Phase 21 ; avant cela, la vérification passait uniquement par le HTML rendu en SSR (`curl` authentifié) et la relecture du bundle compilé, sans observation visuelle réelle — à garder à l'esprit pour tout constat "non vérifié visuellement" antérieur à la Phase 21.
- **PHP CLI local (WSL) sans driver PDO** (ni sqlite, ni mysql, ni pgsql) — la suite de tests ne peut tourner que via un conteneur (`docker compose exec app vendor/bin/phpunit`).
- **Piège sqlite `:memory:`** : `ConnectionAvailability::isAvailable()`/`EnsureConnectionIsNavigable` purge la connexion (`DB::purge`) avant/après test de disponibilité — sur une connexion sqlite `:memory:`, cela reconnecte à une base vierge et efface les tables créées en amont dans un test Feature. Utiliser un fichier sqlite temporaire sur disque plutôt que `:memory:` pour tout nouveau test qui traverse ce middleware. `RelationRepository` saute délibérément cette revérification quand la relation cible la connexion déjà en cours d'utilisation (cf. Phase 9).
- **Collision de classes PHP entre tests** : les modèles Eloquent factices déclarés dynamiquement (`File::put` + `require`) doivent porter un nom de classe unique à l'échelle de *toute* la suite (un seul process PHPUnit, pas d'isolation par fichier).
- **Écriture par instance Eloquent réelle, pas query builder brut** (depuis Phase 4d, EX-424) : nécessaire pour déclencher les événements Eloquent du modèle hôte. La lecture (`paginate()`/`find()`) reste sur le query builder brut.
- **Résolution de clé étrangère/relation limitée à la connexion courante** : une FK ou une relation ciblant une autre connexion que celle de l'item consulté n'est jamais résolue en lien navigable (EX-408/EX-431).
- **sqlsrv jamais vérifié en conditions réelles**, faute d'instance joignable — implémentation plausible (mapping de types, statistiques `sys.partitions`, traduction d'erreurs) mais non éprouvée, contrairement à mysql/pgsql/sqlite.
- **Colonne JSON sur sqlite sous Laravel 11** : indiscernable d'une colonne string (`SQLiteGrammar::typeJson()` ignore `use_native_json` avant Laravel 12) — sans effet sur mysql/pgsql. 3 tests se skippent explicitement sous Laravel 11 pour cette raison.
- **`RelationMethodGuard`** (ex-`RelationMethodDenylist`) est le seul point d'invocation autorisé pour réflexionner une méthode publique du modèle hôte candidate à être une relation Eloquent (cf. Phase 12 pour l'historique des deux bugs de sécurité qui ont mené à sa conception actuelle : allowlist par origine du fichier + denylist explicite en défense en profondeur). **Ne jamais réintroduire un appel direct à `$method->invoke($instance)` en dehors de cette classe.**
- **Bug connu, non corrigé** : `ItemRepository::decorate()`/`find()` ne `json_decode()` jamais la valeur d'une colonne JSON lue en base avant de la renvoyer — `ItemDetail.vue` double-encode donc cette valeur à l'affichage (texte échappé illisible plutôt que JSON indenté). Découvert en Phase 21, corrigé localement côté `ItemForm.vue` uniquement (`parseJsonValue()`). **À corriger côté back dans une phase ultérieure.**

## Architecture technique retenue

- **Backend** : package Laravel 11/12/13 (service provider, config publiable, routes API `api/modelbase/...`). Pas de données propres au plug-in : connexions, modèles et items sont **introspectés dynamiquement** depuis l'application hôte (pas de migrations Eloquent classiques pour ces entités).
- **Frontend** : SPA Nuxt 3, consommant l'API du plug-in en JSON, publiée en build statique dans `resources/dist/modelbase/` et servie par le plug-in via `vendor:publish` (EX-105/EX-106).
- **Environnement de dev/test** : docker-compose local (app hôte de démo + mysql + pgsql), hors périmètre de production.
- **Tests** : Feature test par endpoint API, Unit test par fonction non triviale, conformément à CLAUDE.md.

## État global

| Phase | Module SFD                     | Statut    |
|-------|--------------------------------|-----------|
| 0     | Socle technique                | ✅ fait    |
| 1     | Architecture générale (accès)  | ✅ fait    |
| 2     | Bases de données               | ✅ fait    |
| 3     | Modèles                        | ✅ fait    |
| 4a    | Items — listing & détail       | ✅ fait    |
| 4b    | Items — création/édition       | ✅ fait    |
| 4c    | Items — suppression            | ✅ fait    |
| 4d    | Items — fidélité Eloquent      | ✅ fait    |
| 5     | Ergonomie front (modules 3-4)  | ✅ fait    |
| 6     | Exposition du front            | ✅ fait    |
| 7     | Internationalisation FR/EN     | ✅ fait    |
| 8     | Identité visuelle              | ✅ fait    |
| 9     | Relations entre modèles        | ✅ fait    |
| 10    | Compatibilité Laravel 11/12/13 | ✅ fait    |
| 11    | Filtrage et tri des items      | ✅ fait    |
| 12    | Items soft-deleted             | ✅ fait    |
| 13    | Cache Scout à la demande       | ✅ fait    |
| 9bis  | Revoir diagramme de relations  | ✅ fait    |
| 14    | Disposition item en colonnes   | ✅ fait    |
| 15    | Pagination avancée du listing  | ✅ fait    |
| 9ter  | Navigation depuis diagramme    | ✅ fait    |
| 16    | Chargement progressif listing  | ✅ fait    |
| 17    | Performance listing modèles    | ✅ fait    |
| 18    | Petits ajustements ergonomiques| ✅ fait    |
| 19    | Couverture backend (audit)     | ✅ fait    |
| 20    | Tri du listing des modèles     | ✅ fait    |
| 21    | Modification différentielle    | ✅ fait    |
| 22    | Ajustements ergonomiques       | ✅ fait    |
| 23    | Filtre/tri tableaux liés       | ✅ fait    |
| 24    | Nombre de propriétés (modèles) | ✅ fait    |
| 25    | Listing items = colonnes exposées | ✅ fait    |
| 26    | Cast Eloquent → type de rendu  | ✅ fait    |
| —     | EX-476 (ordre stable des colonnes) | ✅ fait (régularisation) |

---

## Phase 0 — Socle technique

Squelette package Laravel (`quatrebarbes/modelbase`), config publiable (`config/modelbase.php`), squelette Nuxt 3 (`frontend/`), docker-compose (app hôte de démo + mysql + pgsql), pipeline PHPUnit via `orchestra/testbench`.

- Les fichiers édités depuis Windows perdent leur bit exécutable (`app-entrypoint.sh`) — le compose invoque le script via `sh` plutôt que de compter sur `+x`.
- `migrate:fresh` ne réinitialise que la connexion par défaut (mysql) ; `docker/app-entrypoint.sh` fait un `db:wipe --database=pgsql` explicite avant, sinon les tables `Schema::connection('pgsql')` entrent en conflit au redémarrage suivant.

## Phase 1 — Architecture générale (module 1, EX-101 à EX-103)

Middleware d'authentification (`Authenticate`, s'appuie sur le guard configurable de l'app hôte, `modelbase.guard`) appliqué à tout `routes/api.php` — 401 JSON direct, sans redirection, aucune notion de rôle propre au plug-in.

## Phase 2 — Bases de données (module 2, EX-201 à EX-208)

`GET /connections` (nom, driver, statut, nombre de modèles — jamais host/port/identifiants). `Support\ConnectionAvailability` teste la disponibilité à chaud (`DB::purge()` + `getPdo()`), sans cache. `EnsureConnectionIsNavigable` : 404 si connexion inconnue/exclue, 409 si injoignable.

- **Bug corrigé, transverse à toutes les phases** : les routes du plug-in n'étaient rattachées à aucun groupe de middleware Laravel — sans le groupe `web`, le guard d'auth (session-based) ne voyait jamais un utilisateur connecté en HTTP réel (les tests Feature passaient malgré tout via `actingAs()`). Corrigé en ajoutant `'web'` au groupe ; le groupe `web` inclut la vérification CSRF (jeton `XSRF-TOKEN`, cf. Phase 4b).
- Un modèle Eloquent déclaré dont la table n'existe pas réellement en base ne fait pas échouer le listing (`hasTable()` vérifié avant `count()`/`getColumnListing()`) — même garde côté module 4.
- `Support\EloquentModelFinder` (scan de `app/Models`, filtrage par connexion), construit en avance de phase pour permettre le comptage de modèles, est réutilisé tel quel par le module 3.

## Phase 3 — Modèles (module 3, EX-301 à EX-305)

`GET /connections/{connection}/models` (nom, nombre d'items, nombre de colonnes), filtre par nom/table (EX-304). Les modèles listés viennent du code hôte (scan de classes), jamais de la base (EX-305).

- **Bug corrigé** : `useApiClient()` ne transmettait pas le cookie de session en SSR (Nuxt exécute `$fetch` côté serveur sans reprendre les en-têtes de la requête entrante) — corrigé via `useRequestHeaders(['cookie'])` côté serveur uniquement.
- Une route `GET /dev-login` (active seulement en environnement `local`) permet d'authentifier l'utilisateur de démo, faute de scaffolding d'auth dans `demo/`.
- Le front Nuxt tourne dans son propre conteneur (`docker/frontend.Dockerfile`, port 3000), qui proxifie `/modelbase/api/**` vers le conteneur `app` pour rester same-origin (cookies/CSRF).

## Phase 4a — Items : listing & consultation (module 4, EX-401 à EX-411)

`Support\ColumnIntrospector` (mapping colonne → `ColumnType` via `Schema::getColumns()`/`getForeignKeys()`, natif Laravel 11+). `GET .../items` paginé, `GET .../items/{id}` détail typé (rendu par type EX-407, FK résolue en lien de navigation EX-408, NULL distingué de chaîne vide EX-409, FK cassée signalée EX-410).

- **Point ouvert historique EX-402** (colonnes « principales » du listing) : tranché depuis, cf. Phase 25.
- **Bug corrigé (signalé par l'utilisateur)** : un modèle à clé primaire non-`id` cassait la navigation (listing, tableaux liés, sélecteur FK) qui supposaient tous une colonne `id` codée en dur — corrigé via `meta.primary_key` (nom réel, `Model::getKeyName()`) exposé par les endpoints de listing.
- Détection de FK composite (plusieurs colonnes) explicitement hors périmètre — ignorée.

## Phase 4b — Items : création & modification (module 4, EX-412 à EX-417)

`GET .../columns` (schéma indépendant de l'existence d'un item), `POST`/`PATCH`/`PUT .../items`. Formulaire adapté par type (EX-414), sélecteur d'item pour les FK (EX-415, `ItemPicker.vue`), colonnes techniques en lecture seule (EX-416). Erreurs de contrainte BDD (NOT NULL/UNIQUE/FK/format) traduites par `DatabaseErrorTranslator` en 422 (EX-417), sans validation Laravel dupliquée.

- Écriture par query builder brut à cette étape (remplacé par Eloquent réel en Phase 4d, EX-424) : timestamps et encodage JSON posés manuellement par `ItemRepository`.
- Traduction d'erreur par pilote (mysql/pgsql/sqlite) — limite : extraction du nom de colonne non garantie pour un index nommé hors convention Laravel, ou un format d'erreur non couvert (sqlsrv).

## Phase 4c — Items : suppression (module 4, EX-418 à EX-420)

`DELETE .../items/{id}` avec confirmation front (EX-419, `window.confirm()`) et traduction d'une violation FK entrante en 409 (EX-420, jamais de suppression forcée). Sqlite n'applique les contraintes FK que si `foreign_key_constraints` est explicitement configuré — activé dans les tests dédiés.

## Phase 4d — Items : fidélité à la modélisation Eloquent (EX-421 à EX-424, EX-464)

Reprise du cœur d'`ItemRepository` : seules les colonnes `fillable` sont éditables (EX-421/EX-464), les colonnes exposées sont l'union fillable ∪ castées ∪ techniques ∪ FK de relation Eloquent (EX-422, nouvelle `columnsFor()` — **une colonne réelle absente de cette union disparaît entièrement**, décision explicite tranchée avec l'utilisateur), les relations `belongsTo` sont détectées par réflexion des méthodes publiques du modèle hôte (EX-423), et create/update/delete passent désormais par de vraies instances Eloquent pour déclencher les événements du modèle hôte (EX-424, y compris un éventuel `SoftDeletes`).

- **Sécurité de la réflexion (EX-423)** : réflexionner une méthode publique sans paramètre requis peut avoir un effet de bord réel (`save`/`delete`/`push`/...) — un denylist calculé sur `Model::class` + `SoftDeletes` exclut ces méthodes. Point de départ de `RelationMethodGuard`, complété en Phase 9/12 (cf. points d'attention transverses).
- Un modèle hôte sans `$fillable` ni `$guarded` a par défaut `$guarded = ['*']` chez Eloquent : son formulaire serait entièrement en lecture seule. Non affecté pour les modèles de démo.

## Phase 5 — Ergonomie front (modules 3-4, sans exigence SFD dédiée)

Fil d'Ariane (`Breadcrumb.vue`), toasts de confirmation (`useToast`/`Toast.vue`), debounce 300 ms de la recherche de modèles, indicateur de chargement (`<NuxtLoadingIndicator>` + `Spinner.vue`), accessibilité clavier des tableaux (`tabindex`, `Enter`), titre d'onglet dynamique. Revu le 2026-08-19 : le listing des items pilote désormais manuellement `useLoadingIndicator()` sur le `pending` de sa requête, un tri/filtre/page ne provoquant pas de navigation de route donc pas de déclenchement automatique de la barre native.

## Phase 6 — Exposition du front par le plug-in (module 1, EX-104 à EX-106)

**Décision tranchée avec l'utilisateur** : publier le front via `vendor:publish` (tag `modelbase-assets`) plutôt que de ne jamais le servir en production. Routes front (`{prefix}/app`) distinctes des routes API (`{prefix}/api`, EX-105), même middleware `['web', Authenticate::class]`. `SpaController` sert le shell SPA sur une route catch-all.

- **Bug corrigé** : le chemin de publication pointait initialement vers `public_path('vendor/modelbase')`, incompatible avec `app.baseURL` figé dans le build Nuxt (`/modelbase/app/`) — assets JS servis avec `text/html` au lieu du bon MIME type. Corrigé en alignant les deux chemins.
- `npm run build:package` (`MODELBASE_PACKAGE_BUILD=true`) bascule Nuxt en SPA statique (`ssr: false`) ; le conteneur `frontend` du docker-compose (SSR) reste distinct et inchangé.

## Phase 7 — Internationalisation FR/EN (module 1, EX-114 à EX-119)

`@nuxtjs/i18n`, locales `fr`/`en`, détection navigateur désactivée (le français s'affiche toujours en premier accès, EX-115), persistance du choix en `localStorage` (EX-117, plugin dédié pour éviter un flash FR→EN), `fallbackLocale: 'fr'` (EX-119), sélecteur de langue (EX-116). **Périmètre exclu (EX-118)** : noms de colonnes/valeurs d'items et messages `DatabaseErrorTranslator` ne sont jamais traduits (données métier, pas de l'IHM).

## Phase 8 — Identité visuelle (module 1, EX-107 à EX-113)

Tokens `ColorRole` en custom properties CSS (EX-109 à EX-112), mode clair/sombre exclusivement via `prefers-color-scheme` (EX-108, pas de sélecteur manuel).

- **EX-113 (contraste WCAG 4,5:1)** : plusieurs couleurs de rôle brutes échouaient au ratio en usage textuel — résolu en gardant la couleur de rôle brute pour l'usage décoratif (bordures, seuil non textuel 3:1) et en introduisant des variantes dérivées (`--color-primary-fill`, `--color-on-primary-fill`, `--color-error-text`, `--color-hover-text`) pour tout usage textuel. Décision documentée en commentaire de tête de `main.css`.
- Survol (EX-112) : contour (`border`/`outline`) pour boutons/lignes/champs, couleur de texte pour les liens — distinction demandée explicitement par l'utilisateur.

## Phase 9 — Relations entre modèles (module 3 EX-306 à EX-310, module 4 EX-425 à EX-431)

`Support\RelationIntrospector` (réflexion des méthodes de relation, 7 types : `BelongsTo`/`HasOne`/`MorphOne`/`HasMany`/`BelongsToMany`/`MorphMany`/`HasManyThrough`) + `Support\RelationRepository`. `GET .../relations` (module 3, diagramme) et `GET .../items/{item}/relations/{relation}` (module 4, tableau paginé, 409 si connexion cible indisponible EX-431). Navigabilité = modèle cible déclaré **et** connexion cible disponible.

- **Hors périmètre documenté** : attributs de table pivot d'une `belongsToMany` non affichés, pas de mutation depuis ces tableaux.
- **Bug corrigé, grave (signalé par l'utilisateur : consulter/modifier un item le supprimait)** : `RelationMethodDenylist` (garde-fou de réflexion) omettait `forceDeleteQuietly()`/`restoreQuietly()` (`SoftDeletes`, publiques, sans paramètre, absentes de `Model` lui-même) — invoquées à l'aveugle sur une instance réellement récupérée (`find()`), elles supprimaient l'item consulté. Corrigé en deux temps : ajout ponctuel au denylist, puis **refonte en `RelationMethodGuard`** (suite à la question de l'utilisateur sur une allowlist) combinant une **allowlist par origine** (`ReflectionMethod::getFileName()` : la méthode candidate doit être physiquement déclarée dans le fichier du modèle hôte, pas héritée d'un trait/d'une classe parente) et la denylist explicite en défense en profondeur. Ferme toute la classe de bug pour n'importe quel trait, présent ou futur. Limite assumée : une relation déclarée via un trait *propre à l'app hôte* échapperait à l'allowlist (faux négatif cosmétique, jugé préférable au faux positif destructeur). 230 tests au vert après ce correctif.
- Dépendance frontend `mermaid` ajoutée (remplacée par `cytoscape` en Phase 9bis).

## Phase 9bis — Diagramme de relations : disposition en étoile (module 3, EX-310)

**Décision tranchée avec l'utilisateur** (3 options envisagées) : abandon de Mermaid au profit de **Cytoscape.js**, layout `concentric` (modèle courant au centre, poids 2 ; modèles liés en anneau extérieur, poids 1, `levelWidth: 1`). EX-307/EX-309 reformulées dans la SFD pour refléter le rendu réel (disposition radiale, nom + multiplicité seulement — type technique retiré après retouche). Cinq retouches visuelles successives (position des étiquettes de multiplicité, opacité de fond, écartement des liens parallèles).

- **Bug corrigé (signalé par l'utilisateur)** : `MorphTo` étend `BelongsTo` en Eloquent — `relationForeignKeys()`/`RelationIntrospector` matchaient donc aussi une relation polymorphique, exposant des FK auto-référencées absurdes (`Comment.commentable_id` → `comments.id`) avec un lien de navigation vers un item sans rapport. Corrigé en excluant explicitement `MorphTo` des deux méthodes.

## Phase 9ter — Navigation depuis le diagramme de relations (module 3, EX-311)

Clic sur un modèle lié navigable → navigation vers son listing (exigence ajoutée à la demande de l'utilisateur, absente de la SFD jusque-là).

- **Bug corrigé (signalé par l'utilisateur)** : la page listing lit `route.params` dans de simples `const` non réévaluées — naviguer d'un modèle lié à un autre réutilisait l'instance de page existante sans réagir aux nouveaux params. Corrigé via `definePageMeta({ key: (route) => \`${route.params.connection}/${route.params.model}\` })`, en excluant délibérément la query string de la clé (sinon un tri/filtre remonterait aussi la page).

## Phase 14 — Disposition en colonnes de la fiche détail et du formulaire (module 4, EX-448 à EX-451, EX-463)

`.field-grid` : grille de blocs `repeat(auto-fill, minmax(18rem, 1fr))` (repli nativement sur une colonne unique en dessous de 18rem, EX-449). Champs pleine largeur (JSON, texte long — EX-450/EX-463 : détection via `ColumnIntrospector::isLongText()` sur le type SQL réel `text`/`mediumtext`/`longtext`) replacés en fin de grille (EX-451 reformulée dans la SFD).

- **Régression corrigée** : l'annotation « technique, lecture seule » à l'intérieur du `<label>` désalignait les rangées de la grille — déplacée sous le contrôle.

## Phase 15 — Pagination avancée du listing des items (module 4, EX-452 à EX-456)

Choix du nombre de lignes par page (10/25/50/100, EX-452), accès direct à une page (EX-453), repli borné si hors limites côté API (EX-454), mémorisation par contexte de listing via `localStorage` (`usePersistedPerPage`, EX-455/EX-456 — clé `model:{connexion}:{modèle}` ou `relation:{connexion}:{modèle}:{relation}`, jamais liée à un item précis).

- Limite acceptée : la lecture `localStorage` étant synchrone côté client (pas de plugin `.client.ts` dédié comme pour la langue), le SSR utilise toujours la valeur par défaut — un aller-retour réseau supplémentaire est possible au premier rendu client si l'utilisateur avait choisi une autre valeur.
- `ItemPicker.vue` (sélecteur de FK) explicitement hors périmètre (`per_page: 100` fixe).

## Phase 16 — Chargement progressif du listing des connexions (module 2, EX-209 à EX-213)

`GET /connections` scindé : ne renvoie plus que nom/driver (EX-209, plus aucune E/S), nouveau `GET /connections/{connection}/status` pour le statut/comptage (EX-202 inchangée sur le fond). Chaque ligne affichée immédiatement à l'état `checking`, un appel indépendant par connexion déclenché côté client sans attendre les autres (EX-210), mutation en place sans reconstruire le tableau (EX-211/EX-213).

- Revu le 2026-08-19 (commit `e8a5315`) : la colonne statut a désormais une largeur figée (`connection-list__status-column`, calée sur le libellé le plus long) pour ne pas se redimensionner quand l'état `checking` se résout ; une connexion dont le statut n'est pas encore résolu est traitée comme non navigable au même titre qu'une connexion indisponible (`status !== 'available'`, plutôt que `status === 'unavailable'`).

## Phase 17 — Performance du listing des modèles (module 3, EX-302 complétée, EX-312)

Cache court (`Cache::remember()`, TTL configurable) sur `EloquentModelFinder::all()`. Suppression du N+1 de `describe()` (`getTableListing()` une fois par connexion plutôt qu'un `hasTable()` par modèle). **Estimation approchée du nombre d'items pour les grandes tables** (EX-312, arbitrage tranché avec l'utilisateur) : statistiques moteur (`information_schema`/`pg_class`/`sys.partitions`) plutôt que `COUNT(*)` systématique, avec repli sur un comptage exact sous 1000 lignes ou si aucune statistique n'est disponible. Formatage `Support\ApproximateCount::format()` (suffixe K/G/T, toujours tronqué, jamais arrondi au-dessus).

- **Bug corrigé en vérifiant** : `pg_class.reltuples` vaut `-1` (sentinelle, pas 0) pour une table jamais analysée — traité comme estimation inconnue plutôt que casté tel quel.
- Filtre de recherche du listing (EX-304) déplacé côté client (liste déjà chargée en un seul appel).

## Phase 18 — Petits ajustements ergonomiques

Série de retouches ponctuelles sans exigence SFD dédiée : alignement à droite et largeur plafonnée (3rem) des colonnes numériques (modules 2 et 3), **tri par défaut du listing d'items par `updated_at` décroissant** (à défaut clé primaire décroissante) en l'absence de tri explicite (vérifie l'existence réelle de la colonne via `Schema::hasColumn()`), ajustements successifs de couleur/opacité des liens du diagramme de relations.

## Phase 19 — Comblement de trous de couverture backend (audit)

Audit de couverture mené sur `src/` croisé avec `tests/` : suite jugée déjà dense, quelques trous ponctuels comblés (`ModelResolver`, `EloquentModelFinder::classForTable()`, branche `PRIMARY` de `DatabaseErrorTranslator`, FK composite au niveau schéma, route de redirection `/modelbase`, `ModelbaseServiceProvider`, cas limites de `paginate()`). Trous identifiés mais non traités : `filter[colonne][]=...` en tableau sur colonne non-string, garde-fou `per_page` côté `RelationRepository`, sqlsrv toujours non vérifiable.

## Phase 20 — Tri du listing des modèles (module 3, EX-313 à EX-316)

Tri mono-critère (pas de tri multi-colonnes comme le module 4, jugé disproportionné pour ce tableau non paginé) sur les 4 colonnes, géré localement dans `ModelList.vue` (pas de composable partagé avec `ItemList.vue`, le cycle simple asc→desc→aucun tri différant trop du tri multi-critère). Nouveau champ `item_count_raw` (valeur numérique non formatée, nécessaire pour un tri cohérent). État de tri **non reflété dans l'URL** (cohérent avec l'absence de reflet du filtre `search` sur cette même page — asymétrie assumée avec le module 4).

## Phase 21 — Modification différentielle d'un item (module 4, EX-465 à EX-468)

`ItemForm.vue` (prop `editing`) n'émet désormais que les colonnes réellement modifiées par rapport aux valeurs initiales (`hasChanged()`, comparaison par valeur — `deepEqual()` structurel pour JSON, EX-467). Bouton de validation désactivé tant qu'aucun changement (EX-468). Périmètre limité à la modification ; la création transmet toujours l'ensemble des colonnes. **Détection de conflit d'édition concurrente explicitement hors périmètre** (pas de verrouillage optimiste).

- **Bug JSON découvert à cette occasion, cf. « points d'attention transverses »** ci-dessus (non corrigé côté back).

## Phase 22 — Ajustements ergonomiques (module 2 EX-215, module 4 EX-469)

Fusion de deux ajustements mineurs indépendants : troncature à 3 lignes des cellules du listing d'items (EX-469, `-webkit-line-clamp: 3`, régularisation d'un changement déjà codé) et tri alphabétique insensible à la casse du listing des connexions (EX-215, `sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)`).

- **Gap corrigé plus tard (signalé par l'utilisateur)** : la troncature EX-469 n'avait pas été répercutée sur `RelationTable.vue` alors qu'EX-427 exige « la même logique d'aperçu » — corrigé en Phase 23.

## Phase 23 — Filtrage et tri des tableaux d'objets liés (module 4, EX-470 à EX-473)

Extension du mécanisme de filtre/tri du listing standard (Phase 11) à `RelationRepository::paginateRelated()` : `columnsFor()`/nouvelle `columnTypesFor()` rendues publiques et réutilisées, conversion en query builder brut (`->toBase()`) pour appliquer `ItemQueryFilter`, restreint aux colonnes du modèle lié (les attributs de table pivot restent exclus, EX-472). État de filtre/tri propre à chaque tableau, non reflété dans l'URL (EX-471, plusieurs tableaux coexistent sur une même fiche détail).

## Phase 24 — Nombre de propriétés du listing des modèles (module 3, EX-302/EX-313 mises à jour)

`column_count` (nombre brut de colonnes de la table) remplacé par `property_count` (même allowlist qu'EX-422 : fillable ∪ castées ∪ techniques ∪ FK de relation). Extraction de `exposedColumns()`/`exposedColumnNames()` vers `ColumnIntrospector` (candidat naturel, connaissait déjà `relationForeignKeys()}`) ; `ItemRepository::columnsFor()` devient une simple délégation.

- **Point corrigé en cours d'implémentation** : une première version ne restreignait pas les noms déclarés (`$fillable`/casts) aux colonnes réellement présentes dans le schéma — un nom de `$fillable` sans colonne réelle (typo) aurait été compté à tort.
- Un modèle sans `$fillable`/cast/relation `belongsTo` ne compte donc plus que ses colonnes techniques — avertissement explicite ajouté à la SFD.

## Phase 25 — Le listing des items n'affiche que les colonnes exposées (module 4, EX-402 tranché, EX-476)

**EX-402 tranché** : le listing d'items (`ItemRepository::paginate()`) applique désormais un `select()` explicite sur les colonnes de `columnsFor()` (EX-422), au lieu de la ligne brute complète — même règle appliquée à `RelationRepository::paginateRelated()` (EX-427). Écart réel corrigé : le commentaire de classe d'`ItemRepository` affirmait déjà ce comportement avant qu'il ne soit vraiment implémenté.

**EX-476 (régularisation, ajoutée à la SFD et implémentée le 2026-08-14, jamais consignée jusqu'ici)** — L'ordre d'affichage des colonnes du listing doit systématiquement suivre l'ordre d'exposition du modèle (EX-422), y compris sur un listing vide, plutôt que de se déduire des clés de la première ligne renvoyée (ce qui aurait pu faire varier l'ordre d'une page à l'autre). `ItemList.vue`/`RelationTable.vue` dérivent désormais leurs en-têtes du schéma `/columns` plutôt que de `Object.keys(items[0])` (commit `2d0a77f`).

## Phase 26 — Correspondance cast Eloquent → type de rendu (module 4, EX-422 mise à jour, EX-474)

Le type de rendu d'une colonne (EX-407) est déduit en priorité du cast Eloquent déclaré (`Model::getCasts()`), et seulement à défaut du schéma de la base — table de correspondance figée en SFD (EX-474). Ordre de priorité : clé étrangère (toujours prioritaire) > cast reconnu > schéma (dernier recours). Le flag « texte long » (EX-450/EX-463) reste dans tous les cas déduit du schéma, jamais du cast — un cast non reconnu (classe personnalisée, énumération, `AsCollection`) se replie silencieusement sur le schéma, sans erreur.
