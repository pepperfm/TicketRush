#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

docker compose exec -T redis redis-cli FLUSHALL >/dev/null
./vendor/bin/sail artisan migrate:fresh --seed --force
./vendor/bin/sail artisan horizon:terminate || true

echo "TicketRush lab reset: Redis flushed and 10,000 tickets seeded."
