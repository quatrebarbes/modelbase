#!/bin/sh
set -e

# Bundle le seul asset JS du front Livewire nécessitant encore un build Node
# (Cytoscape.js pour le diagramme de relations, module 3, EX-306 à EX-311) —
# bien plus léger que l'ancien build Nuxt complet. Résultat
# (resources/dist/modelbase-diagram/) committé et publié via
# `vendor:publish --tag=modelbase-diagram-assets`.
#
# Conteneur node:22-slim jetable (pas de service docker-compose dédié : ce
# build est occasionnel, pas un serveur de dev à garder démarré).
#
# Connu : `docker run -v` ne résout pas un chemin UNC `\\wsl.localhost\...`
# en chemin Windows absolu depuis un shell Windows/Git Bash (même limite que
# l'ancien build-front-package.sh, cf. docs/roadmap.md) — lancer ce script
# depuis l'intérieur de la distribution WSL dans ce cas
# (`wsl.exe -d Ubuntu -- bash -lc 'sh ./docker/build-diagram-asset.sh'`).

REPO="$(cd "$(dirname "$0")/.." && pwd)"

docker run --rm \
    -v "$REPO":/var/www \
    -w /build \
    node:22-slim \
    sh -c '
        set -e
        cp -r /var/www/resources/js-src /build/js-src
        cd /build/js-src
        npm install --no-audit --no-fund
        npm run build
        rm -rf /var/www/resources/dist/modelbase-diagram
        mkdir -p /var/www/resources/dist
        cp -r ../dist/modelbase-diagram /var/www/resources/dist/modelbase-diagram
        chown -R '"$(id -u)"':'"$(id -g)"' /var/www/resources/dist/modelbase-diagram
    '

echo "--- resources/dist/modelbase-diagram ---"
find "$REPO/resources/dist/modelbase-diagram" -maxdepth 1 | sort
