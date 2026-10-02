# Experiment: <name>

Date: YYYY-MM-DD

## Question

What exactly are we trying to learn?

## Hypothesis

Example:

> PgBouncer transaction pooling will reduce PostgreSQL backend connection pressure at the same arrival rate without materially increasing p95 latency.

## System under test

- Git commit:
- Laravel instances:
- Horizon processes:
- PostgreSQL configuration:
- PgBouncer: on / off
- Octane: on / off
- other relevant changes:

## Load

- k6 scenario:
- target RPS:
- duration:
- pre-allocated VUs:
- max VUs:
- dataset size:
- warm-up:

## SLO / acceptance criteria

- p95:
- p99:
- unexpected error rate:
- invariant checks:

## Before

| Metric | Result |
|---|---:|
| achieved RPS | |
| p50 | |
| p95 | |
| p99 | |
| error rate | |
| PostgreSQL connections | |
| PgBouncer waiting clients | |
| Redis ops/s | |
| queue lag | |

## Bottleneck evidence

What metric, query plan, lock wait, saturation signal or trace points to the bottleneck?

Include commands / screenshots / Grafana panel names as needed.

## Change

Describe **one primary change** made for this experiment.

## After

| Metric | Result |
|---|---:|
| achieved RPS | |
| p50 | |
| p95 | |
| p99 | |
| error rate | |
| PostgreSQL connections | |
| PgBouncer waiting clients | |
| Redis ops/s | |
| queue lag | |

## Result

Did the evidence support the hypothesis?

What improved, what regressed, and what stayed unchanged?

## Next question

What is now the limiting resource or the next hypothesis to test?
