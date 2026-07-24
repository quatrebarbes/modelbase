#!/bin/sh
set -e

cd /var/www/demo

composer install --no-interaction --no-progress

[ -f .env ] || cp .env.example .env

php artisan key:generate --ansi

# migrate:fresh ne réinitialise que la connexion par défaut (mysql) ; les
# migrations exécutées sur pgsql (cf. Schema::connection('pgsql') dans les
# migrations authors/articles) doivent être purgées explicitement, sans quoi
# elles entrent en conflit avec elles-mêmes au redémarrage suivant.
php artisan db:wipe --database=pgsql --force
php artisan migrate:fresh --force
php artisan db:seed --force

exec php artisan serve --host=0.0.0.0 --port=8000
