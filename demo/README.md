# Modelbase — application de démo

Ce dossier est une application Laravel **hôte**, utilisée pour développer et tester le plug-in [`quatrebarbes/modelbase`](../composer.json) en conditions réelles. Elle n'a pas vocation à être déployée telle quelle.

## Le plug-in Modelbase

Modelbase est un plug-in Laravel qui expose, dans l'application où il est installé, un front permettant de parcourir :

- les **bases de données** connectées à l'application ;
- les **modèles Eloquent** déclarés sur chaque base ;
- les **items** de chaque modèle, avec consultation, création, modification et suppression.

La navigation suit une hiérarchie à trois niveaux : *base de données → modèles → items*. Les entités parcourues (connexions, modèles, items) sont introspectées dynamiquement depuis l'application hôte — le plug-in ne possède pas de tables métier propres.

Stack : Laravel 13 (backend) + Nuxt 3 (front SPA).

Les spécifications fonctionnelles détaillées sont dans [`docs/sfd/`](../docs/sfd/), l'avancement dans [`docs/roadmap.md`](../docs/roadmap.md).

## Rôle de cette app de démo

- fournit une application hôte minimale qui requiert le plug-in via un repository `path` ;
- est connectée à plusieurs moteurs de BDD (MySQL, PostgreSQL) via `docker-compose`, pour valider le plug-in sur plusieurs drivers plutôt que de tout mocker ;
- est seedée avec des données d'exemple (catégories/produits, auteurs/articles) servant de terrain de test aux modules Bases de données / Modèles / Items.

## Lancer l'environnement

```bash
docker-compose up
```
