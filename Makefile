.PHONY: bootstrap up down reset test load idempotency ps logs

bootstrap:
	./scripts/bootstrap.sh

up:
	./vendor/bin/sail up -d

down:
	./vendor/bin/sail down

reset:
	./scripts/reset-lab.sh

test:
	./vendor/bin/sail test

load:
	docker compose --profile load run --rm k6

idempotency:
	docker compose --profile load run --rm k6 run /scripts/idempotency.js

ps:
	docker compose ps

logs:
	docker compose logs -f --tail=100
