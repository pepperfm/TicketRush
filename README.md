# TicketRush

**TicketRush is a Laravel high-load laboratory, not a fake “hello world at 10k RPS” benchmark.**

The scenario is a flash sale: a limited ticket inventory opens and many clients try to reserve the same pool concurrently. The repository is designed to demonstrate how a Laravel system evolves under measured load.

> **Invariant:** reserved + sold tickets must never exceed inventory, and retrying the same business request must not create another reservation.

## Stack

- Laravel 13 / PHP 8.5
- PostgreSQL 18 with `pg_stat_statements`
- PgBouncer 1.26 in transaction pooling mode
- Redis 8.10
- Laravel Horizon
- Laravel Octane installed for controlled baseline-vs-Octane experiments
- Nginx
- k6
- Prometheus + Grafana
- PostgreSQL, PgBouncer, Redis and Nginx exporters
- configurable idempotent payment mock
- Docker Compose / Laravel Sail

## Quick start

Only Docker is required on the host:

```bash
./scripts/bootstrap.sh
```

Or:

```bash
make bootstrap
```

Local endpoints:

| Service | URL / port |
|---|---|
| TicketRush API | http://localhost:8080 |
| Horizon | http://localhost:8080/horizon |
| Grafana | http://localhost:3000 |
| Prometheus | http://localhost:9090 |
| PostgreSQL direct | localhost:54320 |
| PgBouncer | localhost:64320 |
| Redis | localhost:63790 |
| Payment mock | localhost:18080 |

Grafana is anonymous-admin **only for this local lab**.

The bootstrap script installs Composer dependencies, builds the official PHP 8.5 Sail runtime and PgBouncer 1.26, starts the complete stack, migrates and seeds **10,000 tickets**.

## API flow

The seeded event has ID `1`.

### Inspect inventory

```bash
curl http://localhost:8080/api/events/1
```

### Reserve a ticket

```bash
curl -i \
  -X POST \
  -H 'Accept: application/json' \
  -H 'Idempotency-Key: demo-request-0001' \
  http://localhost:8080/api/events/1/reservations
```

Repeat the exact request with the same idempotency key: it returns the same reservation instead of creating a duplicate.

### Start payment

```bash
curl -i \
  -X POST \
  -H 'Accept: application/json' \
  http://localhost:8080/api/reservations/<reservation-id>/purchase
```

The HTTP request does **not** wait for the payment provider. It changes the state to `payment_pending` and dispatches processing to Horizon.

The payment mock can deliberately return latency, 500, 429 or a timeout. Its important failure mode is:

```text
provider processed payment
        ↓
response was delayed
        ↓
TicketRush timed out
        ↓
queue retry uses the same idempotency key
        ↓
provider returns the already-created payment
```

This models the real ambiguity that a timeout does not prove the remote operation failed.

## Concurrency strategy

Ticket allocation runs inside a PostgreSQL transaction:

```sql
SELECT ...
FROM tickets
WHERE event_id = ?
  AND status = 'available'
ORDER BY id
FOR UPDATE SKIP LOCKED
LIMIT 1;
```

Concurrent requests can lock different available rows rather than forming a single lock queue.

The database also enforces:

- unique `(event_id, idempotency_key)`;
- a partial unique index preventing multiple active reservations for the same ticket;
- transactional ticket state + Outbox writes.

Reservations expire after two minutes. A delayed Redis job releases the ticket; a scheduled sweep is a recovery path for overdue reservations.

## Outbox / Inbox

State changes write an Outbox event in the **same PostgreSQL transaction** as the business change.

The scheduler publishes unpublished events to Redis. Consumers:

1. lock the Outbox event;
2. record its message ID in Inbox;
3. execute the consumer effect;
4. mark the Outbox event published.

Duplicate delivery is therefore safe and expected rather than treated as impossible.

## Load testing

The default k6 scenario uses `constant-arrival-rate`, so incoming request rate is independent of application latency.

Reset the dataset before a run:

```bash
make reset
```

Run the default test:

```bash
make load
```

Defaults:

```dotenv
K6_RATE=100
K6_DURATION=30s
K6_PRE_ALLOCATED_VUS=100
K6_MAX_VUS=1000
```

Example:

```bash
K6_RATE=1000 K6_DURATION=60s docker compose --profile load run --rm k6
```

Check the idempotency invariant separately:

```bash
make idempotency
```

The default thresholds are intentionally meaningful rather than “any response is success”:

- unexpected HTTP failures < 1%;
- p95 < 250 ms;
- p99 < 750 ms;
- checks > 99%.

Do not raise the thresholds merely to make a benchmark green. Record why the SLO was missed.

## Observability

Open **Grafana → TicketRush → TicketRush Lab** while k6 is running.

The initial dashboard includes:

- PostgreSQL availability, transactions and backend connections;
- PgBouncer active / idle server connections;
- PgBouncer waiting clients;
- Redis command rate;
- Nginx active connections and request rate.

PostgreSQL also loads `pg_stat_statements` and enables `track_io_timing`, so expensive SQL can be inspected directly.

k6 remains the source of end-to-end RPS and latency percentiles. Infrastructure metrics explain *why* those numbers move.

## PgBouncer experiment

TicketRush uses PgBouncer by default:

```dotenv
DB_HOST=pgbouncer
DB_PORT=6432
```

To bypass it:

```dotenv
DB_HOST=pgsql
DB_PORT=5432
```

A separate Laravel connection named `pgsql_direct` is also available for diagnostics.

The point is not to claim that pooling always improves throughput. Compare the two configurations and watch PostgreSQL connections plus PgBouncer waiting clients.

## Horizontal scaling

The Laravel service has no public host port; Nginx is the entry point.

```bash
docker compose up -d --scale laravel.test=4
docker compose restart nginx
```

Then repeat the same k6 scenario.

If four PHP instances only move the bottleneck to PostgreSQL, that is a useful result.

## Octane

Octane is installed but **not enabled for the baseline**.

That is intentional: measure the ordinary Laravel/Sail serving model first. Add Octane as a separate experiment, keep the same dataset and k6 scenario, then compare.

## Failure experiments

Once the baseline is recorded, deliberately break dependencies while load is running:

```bash
docker compose stop payment-mock
docker compose stop horizon
docker compose stop redis
docker compose stop laravel.test
```

Questions to record:

- does HTTP latency change?
- does error rate change?
- does queue lag grow?
- are retries safe?
- can a ticket be sold twice?
- what happens after the dependency returns?

## Experiment workflow

Every performance change should follow:

```text
measure
  ↓
identify bottleneck
  ↓
form hypothesis
  ↓
change one thing
  ↓
repeat the same load
  ↓
compare
```

Use [docs/experiments/TEMPLATE.md](docs/experiments/TEMPLATE.md) for each experiment.

Suggested order:

1. establish the unmodified baseline;
2. find the first SQL / CPU / connection bottleneck;
3. compare direct PostgreSQL and PgBouncer;
4. test Redis caching only for a measured read bottleneck;
5. compare baseline serving and Octane;
6. scale Laravel instances behind Nginx;
7. inject payment failures and observe queue behavior;
8. stress Outbox / Inbox delivery;
9. kill dependencies during load;
10. only then consider larger architectural changes.

The project deliberately does **not** start with Kafka, Kubernetes, sharding or microservices. Those belong in the lab only if measurements produce a problem they actually solve.

## Tests

```bash
make test
```

The feature suite runs against PostgreSQL rather than SQLite because the core experiment depends on PostgreSQL locking and partial indexes.

CI uses PHP 8.5, PostgreSQL 18 and Redis 8.10.

## Useful commands

```bash
make up
make down
make reset
make test
make load
make idempotency
make ps
make logs
```

## Benchmark results

No RPS numbers are committed as claims until they have actually been measured.

That is part of the point of this repository: the useful portfolio artifact is the **history of bottlenecks and evidence-backed improvements**, not an invented “10k RPS” badge.
