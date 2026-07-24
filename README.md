# Modelbase

Plug-in Laravel de gestion des données d'une application Laravel : parcours des connexions de bases de données, des modèles Eloquent qu'elles contiennent, et de leurs items — sans données propres au plug-in (tout est introspecté dynamiquement depuis l'application hôte).

> **Statut** : Phase 0 (socle technique) terminée. Aucune fonctionnalité métier n'est encore exposée — voir [docs/roadmap.md](docs/roadmap.md).

## Prérequis

- PHP 8.3+
- Laravel 13
- Node 20+ et npm (pour le front Nuxt, uniquement si lancé hors Docker)
- Docker + docker-compose (pour l'environnement de dev/test)

## Installation dans une application hôte

```bash
composer require quatrebarbes/modelbase
php artisan vendor:publish --tag=modelbase-config
```

La configuration (`config/modelbase.php`) permet de personnaliser le préfixe des routes du plug-in et d'exclure certaines connexions du listing.

## Structure du repo

- [src/](src/), [config/](config/), [routes/](routes/) — le package Laravel (`Quatrebarbes\Modelbase\`)
- [frontend/](frontend/) — SPA Nuxt 3 consommant l'API du plug-in
- [demo/](demo/) — application Laravel hôte de démonstration, utilisée uniquement en dev/test
- [docker-compose.yml](docker-compose.yml) — environnement de dev/test (app de démo + front Nuxt + mysql + pgsql)
- [docs/roadmap.md](docs/roadmap.md) — plan de développement et avancement
- [docs/sfd/](docs/sfd/) — spécifications fonctionnelles détaillées

## Développement

Lancer l'environnement complet (app hôte + front Nuxt + mysql + pgsql, migrations et seed exécutés automatiquement, dépendances npm installées automatiquement) :

```bash
docker compose up -d
```

- L'app de démo est disponible sur `http://localhost:8000`, avec le plug-in requis en local via un repository `path` composer.
- Le front Nuxt est disponible sur `http://localhost:3000` (conteneur dédié, `docker/frontend.Dockerfile`), et proxifie ses appels API vers le conteneur `app` (cf. `frontend/nuxt.config.ts`, `MODELBASE_API_ORIGIN`).
- Aucune authentification n'existe encore côté app de démo (pas de Breeze/Fortify) : une route `GET /dev-login` (active uniquement en environnement `local`) permet d'ouvrir une session pour l'utilisateur seedé avant d'utiliser le front — à visiter une fois dans le navigateur (`http://localhost:8000/dev-login`).

Pour lancer le front hors Docker (nécessite Node 20+, le CLI Nuxt échoue sur des versions plus anciennes) :

```bash
cd frontend && npm install && npm run dev
```

## Tests

```bash
composer install
composer test
```

Tests Feature par endpoint API, tests Unit par fonction non triviale (introspection de schéma, résolution de FK...).
