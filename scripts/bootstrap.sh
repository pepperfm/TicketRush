#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

if [[ ! -f .env ]]; then
    cp .env.example .env
fi

if [[ ! -f vendor/bin/sail ]]; then
    docker run --rm \
        -v "$PWD:/app" \
        -w /app \
        composer:2 \
        composer install --no-scripts --ignore-platform-reqs
fi

docker compose build laravel.test pgbouncer
docker compose run --rm --no-deps laravel.test composer install

./vendor/bin/sail up -d
./vendor/bin/sail artisan key:generate --force
./vendor/bin/sail artisan migrate --seed --force

printf '\nTicketRush is ready: http://localhost:%s\n' "${APP_PORT:-8080}"
