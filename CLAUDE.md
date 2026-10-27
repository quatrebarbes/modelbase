# Contexte projet

Plug-in Laravel de gestion des données d'une application Laravel.

L'application expose un front HTML dans le projet où elle est installée.

Celui-ci permet de parcourir :
- les bases de données de l'application ;
- les modèles présents dans chaque base ;
- les items de chaque modèle, et de les modifier.

## Stack

- Laravel 11, 12 ou 13 (package) — l'app de démo `demo/` cible spécifiquement Laravel 13
- Laravel Livewire 3 (front, cf. docs/roadmap.md section « Réécriture du front : Nuxt → Livewire »)
- docker & docker-compose

## Conventions de développement

- Nommage des tables : snake_case, pluriel
- Migrations avant models, models avant controllers
- Tests Feature pour chaque endpoint API et chaque composant Livewire full-page
- Tests Unit pour chaque fonction
- Aucun build JS ni `vendor:publish` d'asset pour le front (EX-120) : CSS et JS du package sont inlinés par le layout ; une bibliothèque tierce (Cytoscape.js) est chargée depuis CDN publics avec repli et SRI (EX-121) — une montée de version impose de recalculer le hash `integrity` (cf. `resources/js/relation-diagram.js`)

## Documentation

### Spécifications

Les Spécifications Fonctionnelles Détaillées, au format MarkDown sont placées dans le dossier docs/sfd/.

Les exigences sont numérotées, leur identifiant `EX-...` contient un premier chiffre indiquant le module ou l'application concernées, puis 2 chiffres chrono. Les identifiants d'exigences devront être mis en commentaire dans le code l'implémentant.

Les spécifications fonctionnelles détaillées présentes dans la documentation contient des exigences que les développements réalisées doivent suivre. Une exigence se doit d'être courte, univoque et testable.

### Plan de développement

Le plan et l'avancement sont dans docs/roadmap.md. Consulte-le au début de chaque session et mets-le à jour au fur et à mesure.

