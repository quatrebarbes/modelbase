#!/bin/sh
set -e

cd /var/www/frontend

npm install

exec npm run dev -- --host 0.0.0.0 --port 3000
