#!/bin/sh
set -e

# Build les assets statiques du SPA Nuxt (EX-106) pour le package : mode SPA
# (ssr: false, cf. frontend/nuxt.config.ts) plutôt que le conteneur `frontend`
# du docker-compose (dev/test only, cf. docs/roadmap.md Phase 0/3). Le
# résultat (resources/dist/modelbase/) est destiné à être committé et publié
# côté app hôte via `php artisan vendor:publish --tag=modelbase-assets`.
#
# Le build est fait sur une copie de frontend/ à l'intérieur du conteneur
# (pas sur le bind mount) : lancé pendant que `docker compose up` tourne, un
# build en place écraserait le cache `.nuxt`/`.output` partagé avec le
# conteneur `frontend` (nuxt dev, SSR) — vécu une fois, provoque une erreur
# Vite "#app-manifest" côté serveur de dev tant qu'il n'est pas redémarré.
#
# Usage : ./docker/build-front-package.sh (depuis la racine du repo)

REPO="$(cd "$(dirname "$0")/.." && pwd)"
cd "$REPO"

docker run --rm \
  -v "$REPO":/var/www \
  -w /build \
  -e MODELBASE_PACKAGE_BUILD=true \
  node:22-slim \
  bash -c '
    set -e
    cp -r /var/www/frontend /build/frontend
    cd /build/frontend
    npm install
    npx nuxt generate
    rm -rf /var/www/resources/dist/modelbase
    mkdir -p /var/www/resources/dist
    cp -r .output/public /var/www/resources/dist/modelbase
    chown -R '"$(id -u)"':'"$(id -g)"' /var/www/resources
  '

echo "--- resources/dist/modelbase ---"
find "$REPO/resources/dist" -maxdepth 2 | sort
