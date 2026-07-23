# Roadmap

Dernière mise à jour : 2026-07-23.

Projet actuellement vierge (aucun code). Cette roadmap découle des 4 SFD présentes dans `docs/sfd/` :

1. Architecture générale (EX-101, EX-102)
2. Bases de données (EX-201 à EX-208)
3. Tables (EX-301 à EX-304)
4. Items (EX-401 à EX-420)

## Architecture technique retenue

- **Backend** : package Laravel 13 (service provider, config publiable, routes API `api/modelbase/...`). Pas de données propres au plug-in à ce stade : les « modèles » manipulés (connexions, tables, items) sont **introspectés dynamiquement** depuis l'application hôte (via `Schema`/`DB` sur les connexions listées dans `config/database.php`), pas des tables métier du plug-in. Conséquence : pas de migrations Eloquent classiques pour Table/Item — la convention « migrations avant models avant controllers » s'applique aux éventuelles tables internes du plug-in (ex. si un jour on stocke des préférences d'affichage), pas à ces entités-là.
- **Frontend** : SPA Nuxt 3, consommant l'API du plug-in en JSON.
- **Environnement de dev/test** : docker-compose local, uniquement pour le développement et les tests d'intégration du plug-in — en production les BDD sont entièrement gérées par l'app hôte, hors périmètre du plug-in. Le compose fournit une app hôte Laravel de démo + au moins deux drivers de BDD (mysql, pgsql) pour tester réellement EX-202/EX-204/EX-301 sur plusieurs moteurs plutôt que de tout mocker.
- **Tests** : Feature test par endpoint API, Unit test par fonction non triviale (introspection de schéma, résolution de FK, etc.), conformément à CLAUDE.md.

## État global

| Phase | Module SFD                    | Statut    |
|-------|-------------------------------|-----------|
| 0     | Socle technique               | ⬜ à faire |
| 1     | Architecture générale (accès) | ⬜ à faire |
| 2     | Bases de données              | ⬜ à faire |
| 3     | Tables                        | ⬜ à faire |
| 4a    | Items — listing & détail      | ⬜ à faire |
| 4b    | Items — création/édition      | ⬜ à faire |
| 4c    | Items — suppression           | ⬜ à faire |
| 5     | Points ouverts / backlog      | ⬜ à faire |

---

## Phase 0 — Socle technique

Prérequis à tout le reste, ne couvre pas d'exigence directement.

- [ ] Squelette package Laravel (composer.json, ServiceProvider, autoload PSR-4)
- [ ] Fichier de config publiable (ex. connexions exclues, tables techniques à filtrer)
- [ ] Squelette Nuxt 3 (structure pages/composants, client API)
- [ ] docker-compose (dev/test uniquement, pas de prod) : app hôte Laravel de démo + mysql + pgsql, seed de données de démo
- [ ] Pipeline de test (Pest/PHPUnit) branché en CI locale

## Phase 1 — Architecture générale (module 1)

Règles transversales, à poser avant les modules 2-4 car elles conditionnent l'accès à toutes les routes.

- [ ] Middleware d'authentification appliqué à toutes les routes du plug-in (EX-101) — s'appuie sur le guard d'auth de l'app hôte, pas de rôle spécifique au plug-in
- [ ] Vérification que l'accès à un niveau de navigation ne dépend que de la disponibilité du parent, jamais d'un droit utilisateur (EX-102) — test Feature dédié
- Tests Feature : accès refusé si non authentifié, accès autorisé sans condition de rôle une fois authentifié

## Phase 2 — Bases de données (module 2)

- [ ] Endpoint `GET /connections` listant les connexions de `config/database.php` (EX-201)
- [ ] Pour chaque connexion : nom, driver, statut, nombre de tables (EX-202) — exclusion des infos sensibles host/port/identifiants (EX-203)
- [ ] Détection de disponibilité d'une connexion (tentative de connexion à chaud, sans cache) (EX-204, EX-208)
- [ ] Comptage des tables limité aux connexions disponibles (EX-205)
- [ ] Blocage de la navigation vers une connexion indisponible côté API (EX-206)
- [ ] Front Nuxt : liste des connexions, état visuel disponible/indisponible, navigation vers module 3 (EX-207)
- Tests Feature : listing, masquage des infos sensibles, connexion injoignable simulée, recalcul à chaque appel (pas de cache)

## Phase 3 — Tables (module 3)

- [ ] Endpoint `GET /connections/{connection}/tables` listant les tables adossées à un modèle Eloquent déclaré, hors tables techniques Laravel (EX-301)
- [ ] Mécanisme de détection « table technique » (migrations, jobs, sessions, cache, failed_jobs, + config extensible) — Unit test dédié
- [ ] Pour chaque table : nom, nombre d'items, nombre de colonnes (EX-302)
- [ ] Front Nuxt : navigation table → items (EX-303)
- [ ] Filtre par nom côté listing (EX-304), côté front et/ou query param API
- [ ] Gestion du cas « aucune table éligible » (message, pas d'erreur) — limite documentée
- Tests Feature : listing filtré des tables techniques, comptage colonnes/items, filtre par nom, connexion vide

## Phase 4a — Items : listing & consultation (module 4, partie 1)

- [ ] Introspection des colonnes d'une table (nom, type, FK) → mapping vers `ColumnType` (EX-407 en prépa)
- [ ] Endpoint `GET /connections/{c}/tables/{t}/items` paginé (EX-401, EX-403)
- [ ] Sélection des colonnes « principales » pour l'aperçu du listing (EX-402) — **point ouvert, cf. Phase 5** — afficher toutes les colonnes en attendant que le point soit tranché
- [ ] Gestion table vide (EX-404)
- [ ] Endpoint `GET /connections/{c}/tables/{t}/items/{id}` détail complet (EX-405, EX-406)
- [ ] Rendu par type de colonne côté front, y compris JSON (EX-407)
- [ ] Résolution des FK en lien de navigation vers l'item référencé (EX-408)
- [ ] Distinction visuelle valeur nulle vs chaîne vide (EX-409)
- [ ] Gestion FK cassée (item référencé supprimé/inexistant) avec indicateur dédié (EX-410)
- [ ] Navigation retour détail → listing (EX-411)
- Tests Feature : pagination, détail, FK valide/cassée, valeur nulle, table vide
- Tests Unit : mapping type colonne → rendu, résolution FK

## Phase 4b — Items : création & modification (module 4, partie 2)

- [ ] Endpoint `POST /connections/{c}/tables/{t}/items` (EX-412)
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

- Choix des colonnes « principales » affichées dans le listing des items (limite Module 4) — proposition à valider : premières colonnes non-FK/non-JSON déclarées, configurable par table
- Consultation de la structure d'une table (colonnes/types) indépendamment de la navigation vers ses items — explicitement hors module 3
- Modification/suppression en masse de plusieurs items — explicitement hors module 4

Ces points ne bloquent pas le développement mais doivent revenir sous forme d'exigences SFD complémentaires si le besoin se confirme (cf. skill `ba`).
