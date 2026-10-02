# TicketRush

A high-load laboratory built on Laravel for experimenting with **contention, idempotency, queues, connection pooling, caching, failure handling and measurable bottlenecks**.

The business case is intentionally simple: a flash sale opens for a limited number of tickets and thousands of clients try to reserve them concurrently.

> **Invariant:** reserved + sold tickets must never exceed inventory, and retrying the same request must not create a second reservation.

## Stack

- Laravel 13 / PHP 8.5
- PostgreSQL 18 + `pg_stat_statements`
- PgBouncer 1.26
- Redis 8.10
- Laravel Horizon
- Laravel Octane package for later baseline-vs-Octane experiments
- Nginx
- configurable payment mock
- Docker Compose / Laravel Sail

## Quick start

Only Docker is required on the host:

```bash
./scripts/bootstrap.sh
```

Then:

- API: http://localhost:8080
- PostgreSQL directly: localhost:54320
- PgBouncer: localhost:64320
- Redis: localhost:63790
- payment mock: localhost:18080

The bootstrap script installs Composer dependencies, builds the PHP 8.5 Sail image and **PgBouncer 1.26 from upstream source**, starts the stack, migrates and seeds **10,000 tickets**.

## API

The seeded event has ID `1`.

```bash
curl http://localhost:8080/api/events/1
```

Reserve one ticket:

```bash
curl -i \
  -X POST \
  -H 'Accept: application/json' \
  -H 'Idempotency-Key: demo-request-0001' \
  http://localhost:8080/api/events/1/reservations
```

Repeating the request with the same idempotency key returns the same reservation.

## Why the reservation path is interesting

Allocation is performed in a PostgreSQL transaction using:

```sql
FOR UPDATE SKIP LOCKED
```

Concurrent requests can lock different available tickets instead of queueing behind a single hot row.

Database invariants add another safety layer:

- unique `(event_id, idempotency_key)`;
- partial unique index preventing multiple active reservations for one ticket;
- ticket status changes and Outbox records happen in the same transaction.

Reservations expire after two minutes by default. A delayed Redis job releases the ticket, while the scheduler performs a safety sweep for overdue reservations.

## PgBouncer experiment

The application uses PgBouncer by default:

```dotenv
DB_HOST=pgbouncer
DB_PORT=6432
```

To compare direct PostgreSQL:

```dotenv
DB_HOST=pgsql
DB_PORT=5432
```

There is also a `pgsql_direct` Laravel connection for diagnostics.

## Horizontal scaling

The app container intentionally has no host port. Nginx is the entry point, so application instances can be scaled:

```bash
docker compose up -d --scale laravel.test=4
docker compose restart nginx
```

Do **not** assume four instances are faster. The point is to watch where the bottleneck moves.

## Planned experiment order

1. Baseline reservation throughput.
2. Measure SQL, locks and database connections.
3. Compare direct PostgreSQL vs PgBouncer.
4. Add Redis read caching only where measurements justify it.
5. Compare regular Laravel serving vs Octane.
6. Scale application instances behind Nginx.
7. Process payments asynchronously and inject dependency failures.
8. Publish Transactional Outbox events and make consumers idempotent with Inbox.
9. Add k6 scenarios plus Prometheus / Grafana.
10. Kill workers, Redis, payment mock or an app instance during load.

The important result is **not** a peak RPS screenshot. For every stage record **RPS, p50/p95/p99, error rate, PostgreSQL query/lock metrics and queue lag**, explain the bottleneck, change one thing and measure again.
