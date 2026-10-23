# Roadmap

Dernière mise à jour : 2026-10-22 — Phase 38 (masquage des colonnes `$hidden`/`$visible`, module 4 EX-477 à EX-483, EX-302 précisée) développée et close.

Mise à jour précédente : 2026-08-20 — second passage d'audit Vue → Blade (toolbar du listing d'items, libellé des liens de clé étrangère), demandé par l'utilisateur après le premier audit exhaustif. Cf. entrée dédiée dans la section « Réécriture du front : Nuxt → Livewire » ci-dessous.

Mise à jour précédente : 2026-08-19 — réécriture du front Nuxt en Laravel Livewire (Phases 27 à 37) développée et close, y compris la bascule finale : le front est désormais entièrement Livewire, `frontend/` (Nuxt) supprimé. Cf. section « Réécriture du front : Nuxt → Livewire » ci-dessous pour le contexte et les décisions d'architecture.

Mise à jour précédente : 2026-08-19 — condensation de ce document (cf. note ci-dessous) et ajout rétroactif d'EX-476, jusque-là non consignée.

> **Note sur cette version** : ce document a été fortement condensé le 2026-08-19 (697 → ~250 lignes). L'historique narratif complet (déroulé détaillé de chaque investigation, vérifications manuelles pas à pas, corrections d'erreurs de rédaction antérieures de ce même document) reste consultable via `git log -p -- docs/roadmap.md` ; il n'apportait plus d'information utile à la poursuite du développement une fois les phases closes. Seuls sont conservés : les décisions d'architecture encore actives, les limites documentées dans la SFD, les bugs corrigés dont la cause reste instructive (garde-fous de sécurité notamment), et les points d'attention encore valables aujourd'hui.

**Bug corrigé le 2026-08-17 (mémoire épuisée à la lecture du listing des modèles d'une connexion réelle)** — cause : `ColumnIntrospector::relationForeignKeys()` (EX-423) et `RelationIntrospector::relationsOf()` (EX-307/EX-425) invoquaient à l'aveugle toute méthode publique sans paramètre requis du modèle hôte pour tester si elle renvoie une relation Eloquent ; une méthode exécutant elle-même une lecture non bornée (`return $this->hasMany(...)->get();`) était donc invoquée et chargeait tout son résultat en mémoire avant d'être écartée. Corrigé en deux temps : (1) filtre par type de retour déclaré, qui exclut sans invocation une méthode dont le type ne peut pas être une `Relation` ; (2) nouveau point d'invocation unique `RelationMethodGuard::invoke()`, qui exécute l'appel à l'intérieur d'un `Connection::pretend()` — toute requête réellement déclenchée est alors court-circuitée par Laravel lui-même. Limite documentée : ne protège que la connexion de l'instance elle-même, pas un effet de bord PHP sans requête SQL. 9 tests ajoutés (`RelationMethodGuardTest`). Suite complète (327 tests) au vert.

Cette roadmap découle des 4 SFD de `docs/sfd/` :

1. Architecture générale (EX-101 à EX-119)
2. Bases de données (EX-201 à EX-213, EX-215)
3. Modèles (EX-301 à EX-316)
4. Items (EX-401 à EX-456, EX-463 à EX-483)

**Toutes les exigences EX-101 à EX-483 sont développées.** Audit de cohérence SFD ↔ code réalisé le 2026-08-10 (aucun écart trouvé à cette date).

## Points d'attention transverses, toujours valables

- **Build du diagramme de relations** (`./docker/build-diagram-asset.sh`) : ne fonctionne pas tel quel depuis un shell Windows/Git Bash pointant sur un chemin réseau `\\wsl.localhost\...` (résolution de chemin UNC incompatible avec `docker run -v`/`-w`) — même limite que l'ancien `build-front-package.sh`. Contournement fiable : lancer le script depuis l'intérieur de la distribution WSL. **À relancer et committer dans `resources/dist/modelbase-diagram/` après toute modification de `resources/js-src/`** (cf. CLAUDE.md).
- **Harnais de test du front** : depuis la réécriture en Livewire (Phases 27 à 37), les composants full-page et les composants imbriqués à logique propre (`RelationTable`) sont couverts par des tests Feature (`Livewire::test()`), contrairement à l'ancien front Nuxt (aucun composant Vue testé). Restent hors PHPUnit, vérification manuelle contre l'environnement `docker-compose` réel : rendu Cytoscape, `prefers-color-scheme`, touche Échap, transitions `wire:navigate`.
- **PHP CLI local (WSL) sans driver PDO** (ni sqlite, ni mysql, ni pgsql) — la suite de tests ne peut tourner que via un conteneur (`docker compose exec app vendor/bin/phpunit`).
- **Piège sqlite `:memory:`** : `ConnectionAvailability::isAvailable()`/`EnsureConnectionIsNavigable` purge la connexion (`DB::purge`) avant/après test de disponibilité — sur une connexion sqlite `:memory:`, cela reconnecte à une base vierge et efface les tables créées en amont dans un test Feature. Utiliser un fichier sqlite temporaire sur disque plutôt que `:memory:` pour tout nouveau test qui traverse ce middleware. `RelationRepository` saute délibérément cette revérification quand la relation cible la connexion déjà en cours d'utilisation (cf. Phase 9).
- **Collision de classes PHP entre tests** : les modèles Eloquent factices déclarés dynamiquement (`File::put` + `require`) doivent porter un nom de classe unique à l'échelle de *toute* la suite (un seul process PHPUnit, pas d'isolation par fichier).
- **Écriture par instance Eloquent réelle, pas query builder brut** (depuis Phase 4d, EX-424) : nécessaire pour déclencher les événements Eloquent du modèle hôte. La lecture (`paginate()`/`find()`) reste sur le query builder brut.
- **Résolution de clé étrangère/relation limitée à la connexion courante** : une FK ou une relation ciblant une autre connexion que celle de l'item consulté n'est jamais résolue en lien navigable (EX-408/EX-431).
- **sqlsrv jamais vérifié en conditions réelles**, faute d'instance joignable — implémentation plausible (mapping de types, statistiques `sys.partitions`, traduction d'erreurs) mais non éprouvée, contrairement à mysql/pgsql/sqlite.
- **Colonne JSON sur sqlite sous Laravel 11** : indiscernable d'une colonne string (`SQLiteGrammar::typeJson()` ignore `use_native_json` avant Laravel 12) — sans effet sur mysql/pgsql. 3 tests se skippent explicitement sous Laravel 11 pour cette raison.
- **`RelationMethodGuard`** (ex-`RelationMethodDenylist`) est le seul point d'invocation autorisé pour réflexionner une méthode publique du modèle hôte candidate à être une relation Eloquent (cf. Phase 12 pour l'historique des deux bugs de sécurité qui ont mené à sa conception actuelle : allowlist par origine du fichier + denylist explicite en défense en profondeur). **Ne jamais réintroduire un appel direct à `$method->invoke($instance)` en dehors de cette classe.**
- **Bug historique enfin corrigé (Phase 31/32)** : `ItemRepository::decorate()`/`find()` ne `json_decode()` jamais la valeur d'une colonne JSON lue en base avant de la renvoyer (connu depuis la Phase 21, contourné localement côté `ItemForm.vue` à l'époque). Le nouveau `Livewire\Items\ItemDetail::decodeJsonColumn()` décode défensivement (si chaîne, sinon valeur telle quelle) avant affichage *et* avant calcul du brouillon d'édition — corrige à la fois le rendu (JSON réellement indenté, pas une chaîne doublement encodée) et la comparaison de diff (EX-465 à EX-468, qui aurait sinon toujours considéré une colonne JSON comme modifiée). Le bug reste présent dans `ItemRepository` lui-même (correctif appliqué uniquement côté consommateur Livewire) — à corriger côté back si un autre consommateur de `find()`/`decorate()` apparaît un jour.

## Architecture technique retenue

- **Backend** : package Laravel 11/12/13 (service provider, config publiable, routes API `api/modelbase/...`). Pas de données propres au plug-in : connexions, modèles et items sont **introspectés dynamiquement** depuis l'application hôte (pas de migrations Eloquent classiques pour ces entités).
- **Frontend** : Laravel Livewire 3 (`src/Livewire/`, `resources/views/livewire/`) — routes/web.php, sous `{route_prefix}/app`. Vues, traductions et feuille de style chargées directement depuis le package (aucun `vendor:publish` requis) ; seul le diagramme de relations embarque un bundle JS (Cytoscape.js, `resources/dist/modelbase-diagram/`) publié via `vendor:publish --tag=modelbase-diagram-assets`.
- **Environnement de dev/test** : docker-compose local (app hôte de démo + mysql + pgsql), hors périmètre de production.
- **Tests** : Feature test par endpoint API ou par composant Livewire full-page, Unit test par fonction non triviale, conformément à CLAUDE.md.

## Réécriture du front : Nuxt → Livewire (Phases 27 à 37, closes)

**Contexte** : EX-106 (assets du front publiés via `vendor:publish`) a été supprimée de la SFD à la demande de l'utilisateur (`docs/sfd/1. Architecture générale.md`, marquée `(supprimée)`), ouvrant la voie à un front Laravel Livewire — élimine le double toolchain PHP+Node, le build à committer avant chaque commit, et l'étape `vendor:publish` côté app hôte pour les assets du front. EX-104/EX-105 restent actives : le plug-in continue d'exposer un front et une API JSON sous un préfixe commun, chacun sous un segment dédié — l'API JSON (`routes/api.php`, 327 tests d'origine) n'a **pas été touchée** par cette réécriture, elle reste un livrable à part entière du plug-in.

**Décisions d'architecture** :

- Composants Livewire **class-based**, pas de Volt (Volt cible des composants d'app hôte découverts par chemin de fichier ; un package a besoin de classes chargeables via `loadViewsFrom()`/testables via `Livewire::test(FQCN::class)`).
- Les composants Livewire appellent **directement les classes `Support\*Repository` en PHP**, sans passer par l'API HTTP — comportement identique (mêmes exceptions, mêmes événements Eloquent) sans aller-retour réseau interne. Exception délibérée : le statut de disponibilité de chaque connexion (EX-210/EX-211) reste consommé côté client via l'API JSON existante (`fetch()` direct en Alpine, cf. Phase 28) — une requête Livewire groupe par défaut plusieurs mises à jour de composants déclenchées dans le même tick en un seul cycle serveur, ce qui aurait fait dépendre l'affichage du statut de toutes les connexions de la plus lente d'entre elles.
- 5 composants full-page (un par route : connexions, modèles, listing d'items, détail d'item, création d'item), enregistrés comme routes Laravel classiques — les middlewares existants (`Authenticate`, `EnsureConnectionIsNavigable`, `EnsureModelIsNavigable`) s'appliquent tels quels avant l'instanciation du composant, sans code de garde supplémentaire.
- Décomposition plus fine que prévu au départ, simplifiée en cours de route : ni `ConnectionStatusBadge` ni `RelationTabs` n'ont été retenus comme composants Livewire séparés — le statut d'une connexion est un simple bloc Alpine inline (cf. ci-dessus), et l'onglet actif d'un groupe de relations est un état local d'`ItemDetail` (pas de coordination inter-composants à justifier une classe dédiée). Seul `RelationTable` (un par relation, état de filtre/tri/page local et non reflété dans l'URL, EX-471) est resté un composant à part entière.
- Diagramme de relations (module 3, EX-306 à EX-311) : accessible **depuis le listing des items** (EX-306), pas depuis le listing des modèles — Cytoscape.js conservé tel quel (layout `concentric`), bundle `resources/js-src/relation-diagram.js` construit via `docker/build-diagram-asset.sh`, seul point du front nécessitant encore un build Node.
- i18n : fichiers `resources/lang/{fr,en}/*.php`, **un fichier par groupe de traduction** (`common.php`, `connections.php`, etc. — piège rencontré en Phase 27 : `loadTranslationsFrom()` résout `modelbase::groupe.clé` en cherchant un fichier `groupe.php`, pas une clé de tableau à l'intérieur d'un unique fichier), persistance via **cookie longue durée** (`modelbase_locale`) posé par `Livewire\Shared\LocaleSwitcher` et lu par le middleware `SetLocale`, qui force `'fr'` par défaut sans jamais lire `Accept-Language` (EX-115). Parité des clés fr/en garantie par `tests/Unit/LangKeyParityTest.php`.
- Confirmations destructives : `wire:confirm` (remplacement direct de l'ancien `window.confirm()`).
- Tests : `Livewire::test()` par composant full-page et par composant imbriqué à logique propre (`RelationTable`). Restent hors PHPUnit (vérification manuelle docker-compose, comme précédemment) : rendu Cytoscape, `prefers-color-scheme`, touche Échap, transitions `wire:navigate`.
- Développement mené sous un segment temporaire `{prefix}/app-next` (Phases 27 à 36), le Nuxt continuant à servir `{prefix}/app` sans interruption jusqu'à la bascule finale (Phase 37, renommage simple du groupe de routes).

**Pièges rencontrés** (au-delà de celui d'i18n déjà cité) :

- **Testbench ne découvre pas automatiquement `LivewireServiceProvider`** (contrairement à une vraie app Laravel) — ajouté explicitement à `tests/TestCase.php::getPackageProviders()`.
- **`Livewire::test()` ne passe pas par le routeur** : les middlewares de route (`SetLocale` notamment) ne s'exécutent donc jamais pour ce type de test, contrairement à un vrai accès HTTP — la locale par défaut doit être posée explicitement (`app()->setLocale('fr')`) dans ce cas.
- **Un modèle Eloquent factice déclaré dynamiquement dans un test** (`File::put()` dans `app/Models/`) a besoin d'un `require_once` explicite juste après l'écriture — l'autoloader PSR-4 dynamique de Testbench ne le recharge pas automatiquement (déjà documenté dans les points d'attention transverses, oublié une fois en écrivant la fixture Livewire avant d'être retrouvé).
- **`$errors` est un nom de propriété à éviter sur un composant Livewire** utilisant des vues avec `@error(...)` : la directive Blade attend un `ViewErrorBag` partagé par le framework, qu'une propriété publique du composant du même nom masque silencieusement (`Call to a member function getBag() on array`).
- **`php artisan serve` sert un fichier statique déjà publié sans jamais invoquer Laravel** si l'URL correspond à un fichier réel sous `public/` (ex. un `index.html` publié par un ancien `vendor:publish`) — piège rencontré en vérifiant manuellement la bascule finale : les anciens assets Nuxt publiés dans `demo/public/modelbase/app/` continuaient à répondre 200 sans authentification après la suppression du code source Nuxt, jusqu'à leur suppression manuelle. Sans effet sur un déploiement nginx/PHP-FPM réel configuré correctement, ni sur `FrontRoutingTest`/Testbench (qui invoquent toujours le noyau HTTP directement).
- **Bug signalé par l'utilisateur après la Phase 37, corrigé dans la foulée** : les données affichées ne se rafraîchissaient pas en changeant de page (clic dans l'appli comme boutons précédent/suivant du navigateur) sans recharger manuellement. Cause : `wire:navigate`/`Livewire.navigate()` (utilisés pour toute navigation entre pages — lignes de tableau, liens, `$this->redirect(..., navigate: true)`) mettent en cache côté client les pages déjà visitées pour un retour/avant instantané ; or plusieurs composants embarquent leurs données directement dans un attribut `x-data` évalué au rendu serveur (`ConnectionList`, `ModelList`, diagramme de relations) — une page servie depuis ce cache réaffiche donc les données telles qu'elles étaient à la *précédente* visite, jamais rafraîchies tant qu'aucun rechargement complet n'est déclenché. Corrigé en abandonnant `wire:navigate`/`Livewire.navigate()`/`navigate: true` pour toute navigation **entre pages** (composants full-page distincts) au profit d'une navigation page complète classique (`window.location.href`, liens sans l'attribut `wire:navigate`, `redirect()` sans `navigate: true`) — aucune exigence de la SFD n'imposait de transition façon SPA, et la fraîcheur des données prime. Le mécanisme `#[Url]`/`wire:model.live` (mises à jour **au sein** d'une même page, ex. filtre/tri/pagination du listing d'items) n'est pas concerné, ces échanges passant toujours par une requête Livewire normale, jamais par le cache de page.

- **Bug grave signalé par l'utilisateur, corrigé dans la foulée** : toute interaction Livewire (pagination, tri, filtre, bascule du diagramme) échouait en « Page Expired » (419) alors que le rendu initial de chaque page fonctionnait. Cause réelle, masquée par un message d'erreur trompeur (`LivewireReleaseTokenMismatchException`, normalement destiné à détecter un déploiement pendant qu'un onglet reste ouvert) : `ComponentRegistry::generateClassFromName()` de Livewire ne sait reconstruire une classe de composant à partir du nom embarqué dans `memo.name` qu'en la préfixant du namespace configuré côté app hôte (`livewire.class_namespace`, `App\Livewire` par défaut) — jamais celui d'un package tiers (`Quatrebarbes\Modelbase\Livewire\...`). Le rendu initial fonctionnait car le routeur résout la classe directement depuis `routes/web.php`/`@livewire(FQCN::class)`, sans passer par ce mécanisme de nom ; toute requête `/livewire/update` suivante en avait besoin pour recharger le composant, et échouait silencieusement à trouver la classe. Corrigé en enregistrant explicitement chaque composant via `Livewire::component('modelbase-...', FQCN::class)` dans `ModelbaseServiceProvider::boot()` — geste explicitement documenté par Livewire pour un package tiers, que l'hypothèse initiale (« un FQCN suffit, pas d'enregistrement nécessaire », retenue en phase de conception) ne couvrait que pour le rendu initial. Test de non-régression ajouté (`tests/Feature/Livewire/ComponentRegistrationTest.php`) reproduisant le scénario réel (chargement de page puis vraie requête HTTP vers `/livewire/update`) — `Livewire::test()` ne l'aurait jamais détecté, ce mécanisme de nom n'intervenant que sur un aller-retour HTTP complet.
- **Régression signalée par l'utilisateur, corrigée dans la foulée** : le fil d'Ariane (`Breadcrumb.vue`, Phase 5) n'avait jamais été porté en Livewire — oubli pur et simple, les clés de traduction existaient déjà (`common.breadcrumbLabel`, `itemDetail.breadcrumbItem`, `itemCreate.breadcrumbNew`, portées dès la Phase 27) mais n'étaient utilisées nulle part. Ajouté (`resources/views/livewire/partials/_breadcrumb.blade.php`, partiel Blade sans composant dédié) sur les pages modèles/items/détail/création (Bases de données → connexion → modèle → item).
- **Défaut d'affichage corrigé au passage** : une colonne JSON du listing d'items était rendue dans un `<pre>`, incompatible avec la troncature CSS `-webkit-line-clamp` de `.data-table__cell` (EX-469) — un contenu JSON un peu long débordait de la cellule sans être tronqué, désalignant la ligne. Retiré : la valeur (chaîne JSON brute, cf. bug connu `ItemRepository::decorate()` ci-dessus) s'affiche désormais comme n'importe quelle autre colonne, tronquée normalement.
- **Bug d'affichage plus grave, signalé par l'utilisateur (qui en a identifié lui-même la cause exacte)** : le tableau du listing d'items et des tableaux d'objets liés était mal mis en forme (colonnes désalignées). Cause : `.data-table__cell` (`display: -webkit-box`, technique de troncature à 3 lignes EX-469) appliquée **directement sur le `<td>`** — un `<td>` a besoin de `display: table-cell` pour que l'algorithme de mise en page du tableau calcule correctement largeurs/hauteurs de colonnes, et `-webkit-box` l'écrase. L'ancien `ItemList.vue` appliquait déjà cette classe à un `<div>` **à l'intérieur** du `<td>`, jamais au `<td>` lui-même (vérifié via `git show HEAD:frontend/components/ItemList.vue` avant suppression) — écart introduit lors du portage, invisible en relecture de code seule. Corrigé dans `items/listing.blade.php` et `relations/table.blade.php` (`<td><div class="data-table__cell">...</div></td>`).
- **Bug d'infrastructure locale, sans rapport avec le code du plug-in** : le diagramme de relations ne s'affichait jamais dans l'environnement de démo — l'asset `resources/dist/modelbase-diagram/relation-diagram.js` n'avait jamais été publié dans `demo/public/` (`php artisan vendor:publish --tag=modelbase-diagram-assets` jamais exécuté après son introduction en Phase 35), 404 silencieux côté navigateur. Publié manuellement pour cette démo ; toute app hôte réelle doit exécuter cette commande après avoir mis à jour le plug-in (documenté dans le README).
- **Couleur des liens sans classe (fil d'Ariane, clé étrangère de la fiche détail)** : `a`/`a:hover` n'existait pas dans le CSS original (Vue Router ne produisait jamais de `<a>` sans classe dédiée) — ces liens tombaient donc sur le bleu/souligné par défaut du navigateur au lieu du thème. Règle générique ajoutée à `resources/css/modelbase.css`.
- **`LocaleSwitcher::switchTo()` ne changeait jamais réellement la langue affichée** : `$this->redirect(request()->fullUrl())` redirigeait vers l'URL de la requête **AJAX en cours** (`/livewire/update`), pas vers la page affichée par le navigateur — `request()` désigne toujours la requête HTTP en cours de traitement, jamais la page d'où l'action a été déclenchée. Remplacé par `$this->js('window.location.reload()')`, qui laisse le navigateur recharger sa propre URL courante sans que le serveur ait besoin de la connaître. Test de non-régression en conditions réelles ajouté (`tests/Feature/Livewire/LocaleSwitcherRealHttpTest.php`), même limite de `Livewire::test()` que pour le bug d'enregistrement des composants ci-dessus.

**Audit exhaustif Vue → Blade demandé par l'utilisateur** (« il reste des écarts, fais l'analyse ») après plusieurs allers-retours ponctuels sur des défauts d'affichage — plutôt que d'attendre un signalement de plus, comparaison systématique de chaque vue Blade contre son composant Vue d'origine (récupéré via `git show HEAD:frontend/components/*.vue`, malgré leur suppression du répertoire de travail en Phase 37) menée par un agent dédié. **Constat racine** : `resources/css/modelbase.css` ne portait que les classes de l'ancien `frontend/assets/css/main.css` (fichier partagé) — jamais celles qui vivaient dans le bloc `<style scoped>` propre à *chaque* composant Vue (`.item-detail__*`, `.item-form__*`, `.connection-list__*`, `.model-list__*`, `.item-list__*`, `.item-pagination__*`, `.relation-tabs__*`, `.relation-table__unavailable`, `.breadcrumb__*`, `.toast*`, `.spinner`, `.relation-panel__*`). La Phase 36 (vérification i18n/thème) n'avait vérifié qu'un sens de cette cohérence (aucune classe utilisée en vue sans définition CSS) — jamais l'inverse (aucun style scoped d'origine perdu en route). Corrections apportées suite à cet audit :

- **Toasts (EX-446) totalement silencieux** : `ItemDetail::save()/restore()/reindex()` dispatchaient bien `$this->dispatch('toast', ...)`, mais aucun écouteur n'existait nulle part dans le front Livewire — tous les retours de confirmation (sauvegarde, restauration, échec de réindexation...) étaient perdus sans aucun affichage. Ajouté dans `layout/app.blade.php` : un unique écouteur Alpine global (`@toast.window`) sur le `<body>`, affichant une pile de toasts (`.toast-stack`), sur le modèle du `@modelbase-per-page-changed.window` déjà en place.
- **Formulaires d'item (édition et création) sans aucun style de champ** : `_field.blade.php` ne enveloppait input/select/textarea dans aucun conteneur — tous les champs s'affichaient avec l'apparence native du navigateur (pas de bordure/hover/focus/disabled cohérents avec la charte). Ajouté le wrapper `.item-form__control` (bordure, hover orange, focus gris, disabled grisé) autour de chaque contrôle, plus `.item-form__technical`/`.item-form__error`/`.item-form__checkbox`/`.item-form__actions`.
- **Onglets de relations sans aucune classe CSS** : s'affichaient comme des boutons natifs empilés, sans distinguer l'onglet actif. Ajouté `.relation-tabs__bar`/`.relation-tabs__tab`/`.relation-tabs__tab--active` (soulignement, comme le reste de l'IHM pour le survol EX-112).
- **Fiche détail (lecture) sans structure `<dl>/<dt>/<dd>`** : rendue en `<div>` nus au lieu de `<dl class="item-detail">`, dont dépendait tout le style scoped d'origine (bordure de valeur, lien FK atténué, `.item-detail__null`/`__empty-string`/`__broken-fk`). Restructuré `detail.blade.php` en conséquence.
- **Pagination des tableaux d'objets liés amputée** (EX-429 : « identique au listing standard ») : ni saut direct de page, ni choix du nombre de lignes, ni persistance `localStorage` par relation (`RelationTable.php` exposait déjà `$perPage` côté serveur, sans aucun contrôle côté vue pour le piloter). Créé un partiel commun `partials/_pagination.blade.php` (utilisé par `ItemListing` et `RelationTable`), et ajouté `RelationTable::updatedPerPage()`/`perPageStorageKey()` (même format de clé que l'ancien `usePersistedPerPage('relation:...')`).
- **Diagramme de relations, plusieurs régressions fonctionnelles** (pas seulement visuelles) : (1) pas de dédoublonnage — un modèle lié référencé par plusieurs relations apparaissait en plusieurs nœuds au lieu d'un seul relié par plusieurs arêtes ; (2) nœud central non distingué visuellement du reste ; (3) libellé « connexion indisponible » jamais affiché sur un nœud non navigable (seul le style en tirets restait) ; (4) message d'erreur de rendu jamais affiché (`renderError` posé côté JS mais jamais lu côté vue) ; (5) **panneau large de seulement `24rem`, alors que l'original faisait `min(90vw, 45rem)`** — explique à lui seul le principal signalement « mal dimensionné » de l'utilisateur, non détecté lors de la première tentative de correction (fondée sur un raisonnement CSS sans confronter la largeur au composant Vue d'origine) ; (6) bug de multiplicité (`=== '*'` au lieu de `=== 'many'`, les valeurs réelles étant `'one'`/`'many'`, jamais `'*'`) — les liens `1..*` s'affichaient tous `1..1`. `resources/js-src/relation-diagram.js` réécrit en conséquence, panneau reconstruit en `.relation-panel`/`.relation-panel__backdrop`/`.relation-panel__content` (fond semi-transparent cliquable pour fermer, largeur correcte), bundle reconstruit et republié.
- **`ConnectionList`/`ModelList`** : indicateur de chargement (`.spinner`) absent pendant la vérification du statut d'une connexion ; largeurs de colonnes Statut/Modèles non figées (redimensionnement au moment où le statut résout, l'effet qu'EX-212 vise explicitement à empêcher) ; alignement à droite des colonnes numériques perdu ; affordance de survol (contour orange) non désactivée sur une ligne non navigable, alors qu'elle n'est pas cliquable.
- **Listing d'items/tableaux liés** : ligne de filtre en `<td>` au lieu de `<th>` dans un `<thead>` (HTML non conforme) ; `aria-label` manquant sur chaque champ de filtre (régression accessibilité) ; ligne « supprimé » en `opacity` (délave aussi les bordures) au lieu d'une couleur de texte atténuée ; colonne `deleted_at` d'un item supprimé non mise en évidence (EX-439).
- **Fil d'Ariane** : classes `.breadcrumb`/`.breadcrumb__link`/`.breadcrumb__current`/`.breadcrumb__separator` ajoutées (gris atténué, élément courant en gras — profitait déjà du `a`/`a:hover` générique pour le survol, mais pas du reste).
- **Header de navigation** : `position: absolute` au lieu de `position: fixed` — le sélecteur de langue défilait avec la page. Corrigé une première fois vers `position: fixed`, puis revu une seconde fois (cf. entrée dédiée ci-dessous) : ni l'un ni l'autre n'était en fait justifié par EX-116.

**Bug signalé séparément juste après cet audit** : le bouton Enregistrer du formulaire d'édition ne s'activait jamais, même après une saisie modifiant un champ. Cause : `_field.blade.php` liait chaque champ via `wire:model` (synchronisation **différée** au prochain aller-retour réseau, comportement par défaut de Livewire 3) plutôt que `wire:model.live` (synchronisation immédiate) — `ItemDetail::hasChanges()`, recalculée côté PHP à chaque rendu du composant, ne se réévaluait donc jamais après une frappe, puisqu'aucune requête n'était déclenchée pour la provoquer. `Livewire::test()->set()`, utilisé par les tests existants (`test_editing_only_submits_changed_columns` notamment), simule directement la réception d'une mise à jour déjà arrivée côté serveur — il ne pouvait donc pas détecter ce bug, purement lié au moment où le *navigateur* envoie la valeur, pas à la façon dont le serveur la traite une fois reçue. Corrigé (`wire:model.live`, avec un debounce de 300 ms pour les champs texte/nombre/JSON afin de ne pas envoyer une requête à chaque caractère). Test de non-régression ajouté vérifiant explicitement le HTML rendu (présence de `wire:model.live`, bascule de l'attribut `disabled` du bouton), plutôt qu'un test `Livewire::test()` classique qui n'aurait rien détecté.

**Second passage d'audit demandé par l'utilisateur** (« d'autres points qui ne correspondent pas à la précédente app ? les alignements sont bien en place ? sur les couleurs des boutons, on est bon ? ») après un nouveau bug ponctuel (bouton Enregistrer, ci-dessus) — plutôt qu'attendre un signalement de plus, nouvelle comparaison ciblée des pages/composants pas encore confrontés à l'original lors du premier audit (`git show HEAD:frontend/pages/...`, `git show HEAD:frontend/components/ItemForm.vue`, `RelationTable.vue`). Écarts trouvés et corrigés :

- **Toolbar du listing d'items non conforme sur trois points** (`items/listing.blade.php`) : (1) le lien « + Nouvel item » portait la classe `toolbar__link` (fond pilule neutre) au lieu de `btn btn--primary` (couleur primaire), seul bouton d'action principale de toute l'IHM à ne pas suivre cette convention ; (2) tous les contrôles étaient regroupés dans un unique `.toolbar__actions`, alors que l'original en répartit deux (à gauche : nouvel item + diagramme ; à droite : réinitialiser filtres/tri + filtre des supprimés), ce qui déplaçait visuellement le filtre des supprimés au mauvais endroit une fois le CSS `justify-content: space-between` de `.toolbar` pris en compte ; (3) le filtre des items supprimés était un `<select>` **sans classe**, enveloppé dans un `<label>` à texte visible, au lieu d'un `<select class="btn">` avec seulement un `aria-label` (comme l'original) — rendu visuellement incohérent avec les autres boutons de la barre.
- **Lien de clé étrangère de la fiche détail incomplet** (`items/detail.blade.php`) : le lien affichait seulement la valeur brute (`{{ $value['value'] }}`) sans le nom du modèle lié, et le message de clé étrangère cassée utilisait des parenthèses au lieu du tiret cadratin de l'original — corrigé en `{{ $value['value'] }} ({{ $value['foreign_key']['model'] }})` pour un lien navigable, `{{ $value['value'] }} — {{ __(...) }}` pour une clé cassée.
- **Bouton Annuler superflu sur la page de création d'item** (`items/create.blade.php`) : un lien `Annuler` avait été ajouté vers le listing lors du portage, absent de l'original (`ItemForm.vue` n'affiche ce bouton que si son parent passe `cancellable`, jamais fait par la page de création) — retiré pour ne pas introduire un comportement non spécifié.
- **`RelationTable` sans sa classe racine `.relation-table`** : présente dans l'original (`<section class="relation-table">`) mais absente du portage Blade — sans effet visuel actuel (aucune règle CSS n'en dépend), ajoutée par cohérence structurelle.
- Reste du front (pages Connexions/Modèles, boutons de la fiche détail, classes `.btn`/`.btn--primary`/`.btn--danger`, palette de couleurs `resources/css/modelbase.css`) confronté à l'original sans écart supplémentaire trouvé — le seul absent restant (`Spinner` pendant le chargement initial des pages Connexions/Modèles) n'a pas d'équivalent réel en rendu Livewire server-side (la page est déjà entièrement résolue à l'affichage, contrairement au chargement asynchrone d'origine côté Nuxt).

Vérifié par un test Feature jetable (requête HTTP réelle vers les routes complètes, pas `Livewire::test()`) confirmant le HTML rendu de la toolbar et du lien de clé étrangère, supprimé après vérification — suite complète (363 tests) au vert après ces corrections.

**Positionnement du header de navigation (`header.toolbar`), corrigé une seconde fois** : le premier audit avait remplacé `position: absolute` par `position: fixed`, en lisant EX-116 (« l'utilisateur peut changer la langue à tout moment ») comme une exigence d'épinglage à l'écran pendant le défilement. Relecture du texte exact de la SFD à la demande de l'utilisateur : « à tout moment » qualifie la disponibilité de l'action (l'utilisateur peut agir quand il le souhaite), et la conséquence qui en découle dans la SFD est explicite — « le système de bascule sera présent sur toutes les pages » —, pas un maintien visuel pendant le scroll. `position: fixed` risquait au contraire de recouvrir le contenu de page (aucune marge/`padding-top` réservée pour lui sur `<main>`). Retiré entièrement : `header.toolbar` reste dans le flux normal du document, comme n'importe quel autre header de page.

Chaque phase de cette réécriture s'est clôturée par un `composer test` complet — aucune n'a touché `routes/api.php`, aux contrôleurs HTTP existants ni à `src/Support/**` ; le socle backend n'a jamais eu besoin d'être modifié pour ce front.

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
| 27    | Livewire — Socle               | ✅ fait    |
| 28    | Livewire — Connexions          | ✅ fait    |
| 29    | Livewire — Modèles             | ✅ fait    |
| 30    | Livewire — Listing items       | ✅ fait    |
| 31    | Livewire — Détail item         | ✅ fait    |
| 32    | Livewire — Édition/création    | ✅ fait    |
| 33    | Livewire — Suppression/reindex | ✅ fait    |
| 34    | Livewire — Relations           | ✅ fait    |
| 35    | Livewire — Diagramme relations | ✅ fait    |
| 36    | Livewire — Vérif. transverse i18n/thème | ✅ fait |
| 37    | Livewire — Bascule finale      | ✅ fait    |

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

## Phase 27 — Livewire : socle (module 1, EX-101 à EX-103 réutilisées, EX-114 à EX-119)

Première phase de la réécriture du front en Livewire (cf. section dédiée ci-dessus pour le contexte et le plan complet). Dépendance `livewire/livewire` (^3.0) ajoutée au `composer.json` du package. `ModelbaseServiceProvider` charge désormais les vues (`loadViewsFrom`, namespace `modelbase`) et les traductions (`loadTranslationsFrom`) du package, en plus de `routes/livewire.php` (nouveau, groupe temporaire `{prefix}/app-next`, mêmes middlewares `web`/`Authenticate` que l'ancien front + nouveau `SetLocale`).

- **Feuille de style inlinée, pas publiée** : `resources/css/modelbase.css` (port verbatim de `frontend/assets/css/main.css`, même palette EX-107 à EX-113) est injectée directement dans `<style>` par le layout Blade (`ModelbaseServiceProvider::cssPath()` + `file_get_contents()`) — aucune étape `vendor:publish` requise pour cet asset, contrairement à EX-106 (désormais supprimée).
- **i18n (EX-114 à EX-119)** : `resources/lang/{fr,en}/*.php`, **un fichier par groupe de traduction** (`common.php`, `connections.php`, `models.php`, `items.php`, `itemDetail.php`, `relations.php`, `itemForm.php`, `itemCreate.php`, `locale.php`) — port direct des clés des JSON Nuxt (`frontend/i18n/locales/*.json`). **Piège rencontré** : `loadTranslationsFrom($path, 'modelbase')` résout `__('modelbase::common.databases')` en cherchant un fichier `common.php` (le premier segment après `::` est le **nom de fichier**, pas une clé de tableau à l'intérieur d'un unique fichier `modelbase.php`) — écrit une seule fois avec la mauvaise structure puis corrigé avant la fin de la phase. Parité stricte des clés fr/en garantie par un test dédié (`tests/Unit/LangKeyParityTest.php`) plutôt que par un mécanisme de repli runtime. `SetLocale` (nouveau middleware) pose la locale par défaut (`'fr'`, sans jamais lire `Accept-Language`, EX-115) depuis un cookie `modelbase_locale` (EX-117 : cookie « forever » plutôt que la session de l'app hôte, dont la durée de vie est bornée). `Livewire\Shared\LocaleSwitcher` pose ce cookie et déclenche un `$this->redirect(..., navigate: true)` plutôt qu'un ré-affichage in-place (les autres composants de la page ont déjà rendu leurs chaînes sous l'ancienne locale au moment de l'action).
- **Composant temporaire `Livewire\Shared\Placeholder`** : valide bout en bout l'authentification/le layout/la feuille de style/l'i18n avant que la Phase 28 ne le remplace par le vrai listing des connexions.
- **Piège de test** : Orchestra Testbench ne découvre pas automatiquement les service providers des dépendances du package (contrairement à une vraie app Laravel) — `LivewireServiceProvider` a dû être ajouté explicitement à `tests/TestCase.php::getPackageProviders()`, sans quoi tout rendu Livewire échoue (`Target class [livewire] does not exist.`).
- **Piège de test** : `Livewire::test()` monte un composant directement, **sans passer par le routeur ni ses middlewares** — `SetLocale` ne s'exécute donc jamais pour ce type de test, contrairement à un vrai accès HTTP (`$this->get(...)`). La locale par défaut doit être posée explicitement (`app()->setLocale(SetLocale::DEFAULT)`) dans un test qui isole un composant de cette façon.
- **Piège de test** : dans `tests/Unit/LangKeyParityTest.php`, charger un fichier de traduction via `require_once` échoue silencieusement (renvoie `true` au lieu du tableau) dès que Laravel a déjà chargé ce même fichier via son propre résolveur de traductions plus tôt dans le même process PHPUnit — utiliser `require` (sans `_once`).
- Suite complète vérifiée : 337 tests (327 existants inchangés + 10 nouveaux) au vert via `docker compose exec app composer test` (conteneur réel). Vérifié également en conditions réelles contre le conteneur `app` du docker-compose (`demo/`, après `composer update` pour propager la nouvelle dépendance via le repository `path`) : `GET /modelbase/app-next` renvoie 401 sans authentification puis 200 avec le layout/la feuille de style/le sélecteur de langue une fois authentifié (`/dev-login`), l'ancien front Nuxt (`{prefix}/app`) et l'API (`{prefix}/api`) restant inchangés et fonctionnels en parallèle.
- **Point d'attention noté en vérifiant, sans rapport avec cette phase** : `curl` non authentifié sur `/modelbase/app` (ancien front Nuxt) renvoie 200 au lieu de 401 lorsque servi par `php artisan serve` — artefact du serveur de développement PHP intégré, qui sert directement `public/modelbase/app/index.html` comme fichier statique existant sans jamais invoquer le noyau HTTP de Laravel (donc sans middleware). Comportement pré-existant depuis la Phase 6, propre à `php artisan serve` (n'affecte pas un déploiement nginx/PHP-FPM réel ni la nouvelle route `/modelbase/app-next`, qui n'a aucun fichier statique équivalent), déjà couvert correctement par `FrontRoutingTest` (Testbench invoque toujours le noyau HTTP directement).

## Phases 28 à 37 — Livewire : composants, diagramme et bascule finale

Suite et fin de la réécriture du front (cf. section « Réécriture du front : Nuxt → Livewire » ci-dessus pour les décisions d'architecture et les pièges rencontrés, communs à ces dix phases). Développées à la suite, sans pause de vérification manuelle intermédiaire entre chacune (demandé explicitement par l'utilisateur — vérification globale repoussée à la fin de la Phase 37), mais avec un `composer test` complet après chaque étape significative.

- **Phase 28 (module 2)** — `Livewire\Connections\ConnectionList` : nom/driver résolus en PHP (EX-209), statut/nombre de modèles résolus côté client par ligne via `fetch()` direct sur l'API existante (EX-210/EX-211, cf. décision d'architecture ci-dessus), navigation conditionnée au statut `available` (EX-206/EX-207).
- **Phase 29 (module 3, hors diagramme)** — `Livewire\Models\ModelList` : liste chargée en un seul appel PHP, filtre (EX-304) et tri mono-critère à 3 états (EX-313 à EX-316) entièrement côté client (Alpine), modèle sans table réelle relégué en fin de tri quel que soit le sens (comme l'ancien `ModelList.vue`).
- **Phase 30 (module 4)** — `Livewire\Items\ItemListing` : filtre par colonne (debounce 300 ms) et tri multi-colonnes (`Livewire\Concerns\FiltersAndSortsColumns`, même encodage `col,-col2` qu'`ItemQueryFilter`) synchronisés dans l'URL via `#[Url(history: false)]` (EX-432 à EX-436), filtre « supprimés » (EX-437/EX-438), pagination (EX-452 à EX-456 — persistance du nombre de lignes par page en `localStorage`, avec le même compromis qu'en Phase 15 : rechargement JS au premier affichage si la valeur stockée diffère de l'URL).
- **Phase 31/32 (module 4)** — `Livewire\Items\ItemDetail` (bascule affichage/édition en place) et `Livewire\Items\ItemCreate` : rendu par type de colonne (EX-405 à EX-411), grille de champs responsive avec champs JSON/texte long en fin de grille (EX-448 à EX-451, EX-463), sélecteur de FK par `<select>` peuplé des 100 premières lignes du modèle référencé (EX-415, même limite assumée que l'ancien `ItemPicker.vue`), modification différentielle par comparaison structurelle (`Livewire\Concerns\DiffsItemAttributes`, EX-465 à EX-468) — seules les colonnes réellement modifiées sont soumises, bouton de validation désactivé sans changement.
- **Phase 33 (module 4)** — suppression/restauration/reindex intégrées à `ItemDetail` : `wire:confirm` avec libellé dépendant de `ModelTraitInspector::uses(SoftDeletes)` (EX-418 à EX-420), actions Restaurer/Supprimer définitivement affichées à la place d'Éditer/Supprimer pour un item déjà supprimé (plage soft-delete), action Réindexer affichée seulement pour un modèle `Searchable`.
- **Phase 34 (module 4)** — `Livewire\Relations\RelationTable`, un par relation non-`BelongsTo` déclarée par l'item courant, état de filtre/tri/page propre à chaque instance et jamais reflété dans l'URL (EX-470 à EX-473) ; l'onglet actif (si plusieurs relations) est un simple état local d'`ItemDetail`, pas un composant séparé (cf. décision d'architecture ci-dessus).
- **Phase 35 (module 3)** — diagramme de relations accessible depuis `ItemListing` (EX-306), Cytoscape.js dans un bloc `wire:ignore` piloté par Alpine (`resources/js-src/relation-diagram.js`), couleurs lues depuis les custom properties CSS de la page au moment du rendu (cohérence clair/sombre sans dupliquer la palette). Bundle construit via `docker/build-diagram-asset.sh` (conteneur node jetable, ~1 Mo non minifié, cytoscape inclus) et publié via le nouveau tag `modelbase-diagram-assets`.
- **Phase 36** — vérification transverse i18n/thème : tous les appels `__('modelbase::...')` du front audités contre les fichiers de langue fr/en (aucune clé manquante), classes CSS utilisées dans les vues Livewire recoupées avec `resources/css/modelbase.css` (aucune classe orpheline) — en plus de `LangKeyParityTest` (Phase 27), qui couvre déjà tous les groupes de traduction ajoutés depuis.
- **Phase 37 (module 1, EX-104/EX-105)** — bascule finale : `routes/web.php` réécrit pour servir directement les composants Livewire sous `{prefix}/app` (route nommée `modelbase.web.*`, remplace l'ancien groupe temporaire `modelbase.web.next.*`) ; suppression de `frontend/` (SPA Nuxt), `resources/dist/modelbase/` (ancien build), `src/Http/Controllers/SpaController.php`, `docker/build-front-package.sh`, `docker/frontend.Dockerfile`/`frontend-entrypoint.sh`, et du service docker-compose `frontend` (+ son volume nommé) ; `ModelbaseServiceProvider` n'expose plus que le tag `modelbase-diagram-assets` (le tag `modelbase-assets`/EX-106 disparaît) ; `tests/Feature/FrontRoutingTest.php` réécrit pour vérifier EX-104/EX-105 sur la nouvelle architecture (l'ancien test vérifiait le shell SPA publié, structurellement obsolète). Logo `frontend/public/logo.png` déplacé vers `docs/logo.png` avant suppression (référencé par `README.md`).
    - **Piège de bascule rencontré en vérifiant manuellement** : l'app de démo avait un ancien build Nuxt déjà publié dans `demo/public/modelbase/app/` (via un `vendor:publish --tag=modelbase-assets` exécuté avant la bascule) — ces fichiers, non gérés par le dépôt, ont continué à répondre à la place du nouveau front Livewire (même artefact `php artisan serve` que documenté en Phase 27) jusqu'à leur suppression manuelle. Une vraie mise à jour de plug-in n'a pas ce problème (rien à publier pour le front Livewire), mais toute app hôte ayant déjà publié l'ancien SPA devra supprimer `public/{route_prefix}/app` elle-même après mise à jour.
    - Suite complète vérifiée à l'issue des dix phases : 359 tests au vert (`composer test`, conteneur réel), plus un parcours de bout en bout contre l'environnement docker-compose réel (mysql) : connexions → modèles → listing d'items → détail d'item, avec données réelles de l'app de démo.

**Limite assumée, commune aux Phases 28 à 37** : contrairement aux phases du backend (modules 2 à 4, développées avec vérification manuelle systématique contre docker-compose après chaque phase), ce lot a été développé phase après phase sans un tel arrêt intermédiaire, à la demande explicite de l'utilisateur (« go pour toutes les phases restantes, je testerai à la fin »). La vérification automatisée (`composer test`) est restée systématique à chaque étape ; seule la vérification manuelle/visuelle a été repoussée à la fin.

## Phase 38 — Masquage des colonnes `$hidden`/`$visible` (module 4, EX-477 à EX-483 ; module 3, EX-302 précisée) — close

**Constat** : aucune vue ne tient compte de `$hidden`/`$visible` aujourd'hui. Exemple dans `demo/` : `User::password` (fillable, cast `hashed`, `#[Hidden]`) est affiché en clair (hash) dans le listing et la fiche. La lecture se fait par query builder brut (`ItemRepository::find()`/`paginate()`), donc même une sérialisation `toArray()` n'aurait rien masqué.

**Point d'entrée unique** : toutes les vues (listing, fiche, formulaire, filtre/tri, tableaux d'objets liés, endpoint `/columns`, nombre de propriétés) et toutes les écritures (`ItemRepository::writable()`) dérivent déjà de `ColumnIntrospector::exposedColumns()`. Le retrait des colonnes masquées à cet endroit couvre donc EX-478/EX-481 et la précision d'EX-302 sans modifier chaque consommateur.

1. **`ColumnIntrospector`** :
    - nouvelle méthode `isHidden(Model $instance, string $column): bool` — `in_array($column, $instance->getHidden())` ou (`getVisible()` non vide et colonne absente) (EX-477). Lue sur une instance vide (`new $class`), sans aucun appel à la connexion (EX-480). `#[Hidden]`/`#[Visible]` (Laravel 13) sont déjà fusionnés dans `getHidden()`/`getVisible()` par `initializeHidesAttributes()` au constructeur ; sous Laravel 11/12, seules les propriétés existent — rien à faire de plus.
    - `exposedColumns()` : exclure toute colonne masquée sauf `getKeyName()` (EX-478, EX-479).
    - nouvelle méthode `hiddenColumnNames(string $connection, Model $instance): array` — colonnes réelles de la table (`forTable()`) masquées, hors clé primaire, dans l'ordre du schéma (EX-482).
2. **`ItemDetail` (Livewire) + vue** : bloc listant les noms renvoyés par `hiddenColumnNames()`, rendu seulement s'il est non vide (EX-482/EX-483). Pas de valeur, pas de lien. Rien dans `ItemListing` ni `RelationTable` (cf. avertissement SFD). Clés i18n fr/en dans `itemDetail.php` (`LangKeyParityTest`).
3. **API JSON** : aucune modification attendue (`/columns`, `/items`, `/items/{id}` s'appuient sur `columnsFor()`). Ne pas exposer la liste des colonnes masquées via l'API sans exigence dédiée.
4. **Commentaires de code** : référencer EX-477 à EX-483 aux endroits modifiés ; mettre à jour le docblock d'`exposedColumns()` (allowlist « moins colonnes masquées »).

**Tests** :
- Unit `ColumnIntrospectorTest` : `$hidden` seul ; `$visible` seul (colonnes absentes masquées) ; `$hidden` + `$visible` ; colonne masquée mais fillable / castée / FK de relation (toujours exclue) ; clé primaire masquée (toujours exposée, absente de `hiddenColumnNames()`) ; nom présent dans `$hidden` sans colonne réelle (ignoré par `hiddenColumnNames()`).
- Feature : listing et fiche sans la colonne ni sa valeur (vérifier l'absence de la valeur brute dans le HTML et le JSON) ; filtre/tri sur une colonne masquée passé dans l'URL → rejeté comme une colonne inconnue (EX-481) ; formulaire de création/modification sans le champ, et valeur soumise de force ignorée à l'écriture ; tableau d'objets liés appliquant le masquage du modèle lié ; `property_count` du listing des modèles (EX-302) ; bloc « colonnes masquées » présent/absent sur la fiche (EX-482/EX-483).
- Modèles de test : noms de classe uniques à l'échelle de la suite (cf. points d'attention transverses).

**Compatibilité snow-driver** (`../snow-driver`, vérifiée le 2026-10-22) : `ServiceNowModel` étend `Illuminate\Database\Eloquent\Model` sans surcharger `getHidden()`/`getVisible()`/`getFillable()` ; le générateur de modèles (`ModelFileGenerator`) ne produit ni `$hidden` ni `$visible`. Comportement inchangé pour les modèles générés ; un `$hidden` ajouté à la main est respecté. `hiddenColumnNames()` réutilise `forTable()` (introspection déjà mise en cache par snow-driver), sans requête supplémentaire. À vérifier manuellement contre la démo snow-driver en fin de phase.

**Décision à noter** : les noms affichés par EX-482 sont restreints aux colonnes réellement présentes dans la table — un nom de `$hidden` désignant un accesseur, une relation ou un attribut `$appends` n'est pas une colonne et n'est pas signalé. Le caractère masqué reste, lui, déterminé sans accès à la source (EX-480).

**Effet de bord assumé (cf. avertissement SFD sous EX-481)** : sur `demo/`, `User::password` n'est plus saisissable ; créer un `User` via modelbase échoue désormais si la colonne est `NOT NULL` sans défaut — l'erreur est remontée par le mécanisme existant (EX-417).

**Vérification manuelle** : docker-compose (`demo/`, mysql) — `users` : `password` et `remember_token` absents du listing/de la fiche/du formulaire, cités par leur nom sur la fiche ; modèle sans masquage : aucune mention.

**Bilan (2026-10-22)** — implémenté conformément au plan ci-dessus, sans écart : `ColumnIntrospector::isHidden()`/`hiddenColumnNames()`, filtre dans `exposedColumns()`, `ItemRepository::hiddenColumns()`, bloc `item-detail__hidden-columns` dans `ItemDetail` (clé i18n `itemDetail.hiddenColumns` fr/en). 15 tests ajoutés (7 Unit `ColumnIntrospectorTest` dont l'attribut `#[Hidden]` et l'absence de requête pour EX-480, 6 Feature Livewire `HiddenColumnsTest`, 2 Feature API dans `ItemListingTest`). Suite complète : 378 tests au vert (`docker compose exec -w /var/www app composer test`). Vérifié contre `demo/` (mysql) : fiche `User` sans le hash `password` (HTML et API), mention « Colonnes masquées par le modèle : `password`, `remember_token` », `property_count` de `User` passé à 6. **Non fait** : la vérification manuelle contre la démo snow-driver (aucune instance ServiceNow disponible dans cette session) — la compatibilité repose sur l'analyse du code de snow-driver ci-dessus.

## Phase 39 — Correctif : colonne ambiguë dans un tableau d'objets liés (module 4, EX-427, EX-472) — close

**Constat** (remonté par l'app hôte Sing4u middleware, pgsql, 2026-10-23) : ouvrir une relation `hasManyThrough` (`Mop3::mop3Suivi`, via `mop3_commandes`) échoue en `SQLSTATE[42702] column reference "id" is ambiguous`. `RelationRepository::paginateRelated()` restreignait la requête aux colonnes exposées du modèle lié (EX-427) sans les qualifier par leur table ; la table intermédiaire jointe partage `id` et les timestamps. Même défaut pour le filtre et le tri (EX-472), et pour un `belongsToMany` dont la table pivot a une colonne de même nom que le modèle lié.

**Correctif** : `paginateRelated()` qualifie les colonnes du `select` par la table du modèle lié (`getRelated()->getTable()`) ; `ItemQueryFilter::applyFilters()`/`applySort()` acceptent un paramètre optionnel `$table` qui qualifie les colonnes filtrées/triées (sans effet sur `ItemRepository::paginate()`, qui ne joint aucune table). Les clés des lignes renvoyées restent les noms de colonnes nus.

**Tests** : 2 tests Unit dans `RelationRepositoryTest` (relation `RelRepoCategory::reviews()` hasManyThrough via `products`) — aperçu des colonnes, puis filtre et tri sur `id`. Reproduits en échec avant le correctif (`ambiguous column name: id` sous sqlite). Suite complète : 380 tests au vert.
