# Modelbase

Plug-in Laravel de gestion des données d'une application Laravel : parcours des connexions de bases de données, des modèles Eloquent qu'elles contiennent, et de leurs items — sans données propres au plug-in (tout est introspecté dynamiquement depuis l'application hôte).

> **Statut** : Phase 0 (socle technique) terminée. Aucune fonctionnalité métier n'est encore exposée — voir [docs/roadmap.md](docs/roadmap.md).

## Prérequis

- PHP 8.3+
- Laravel 13
- Node 18+ et npm (pour le front Nuxt)
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
- [docker-compose.yml](docker-compose.yml) — environnement de dev/test (app de démo + mysql + pgsql)
- [docs/roadmap.md](docs/roadmap.md) — plan de développement et avancement
- [docs/sfd/](docs/sfd/) — spécifications fonctionnelles détaillées

## Développement

Lancer l'environnement de démo (app hôte + mysql + pgsql, migrations et seed exécutés automatiquement) :

```bash
docker compose up -d
```

L'app de démo est alors disponible sur `http://localhost:8000`, avec le plug-in requis en local via un repository `path` composer.

Lancer le front en développement :

```bash
cd frontend && npm install && npm run dev
```

## Tests

```bash
composer install
composer test
```

Tests Feature par endpoint API, tests Unit par fonction non triviale (introspection de schéma, résolution de FK...).
