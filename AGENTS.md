# TicketRush Agent Guide

TicketRush is a high-load engineering lab and portfolio case study.

## Core rules

- Preserve the business invariants:
  - reserved + sold inventory must never exceed total inventory;
  - the same idempotency key must never create a second business operation;
  - queue retries and duplicate event delivery must be safe.
- PostgreSQL is intentional. Do not replace integration tests with SQLite when behavior depends on PostgreSQL locks, partial indexes or transaction semantics.
- Do not add a technology solely because it is associated with high load.
- Before optimizing, capture a repeatable baseline with the existing k6 scenario.
- Change one major variable per experiment whenever possible.
- Every performance claim must be backed by recorded measurements.
- Prefer database constraints as the final safety net for invariants that can be expressed in the database.

## Current architecture

HTTP:
k6 -> Nginx -> Laravel -> PgBouncer -> PostgreSQL

Async:
Laravel -> Redis -> Horizon workers

Reliability:
reservation TTL + delayed expiry job + scheduled recovery sweep

Events:
business transaction -> Outbox -> Redis queue -> Inbox-deduplicated consumer

Observability:
Prometheus <- PostgreSQL / PgBouncer / Redis / Nginx exporters
Grafana <- Prometheus

## Performance work

When changing performance-sensitive code:

1. state the hypothesis;
2. record topology and k6 parameters;
3. capture before metrics;
4. make the change;
5. rerun the same test;
6. capture after metrics;
7. explain the result, including regressions.

Use `docs/experiments/TEMPLATE.md`.

## Avoid premature complexity

Do not introduce Kafka, Kubernetes, microservices, database replicas, sharding or a different datastore unless an experiment documents the limitation being addressed.

Octane is already installed specifically for a controlled before/after experiment. Do not silently make it the baseline.
