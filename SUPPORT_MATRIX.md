# Support matrix

This file is the repository-level support contract for engines, auxiliary services and DB strategies.

It must be read together with:

- [`README.md`](README.md)
- [`docs/CONTRATO.md`](docs/CONTRATO.md)
- `core/php/config/ConfigSchema.php`

Do not infer support from Docker service names, optional compose overlays or partial adapters. The current closed path is deliberately narrow.

## Summary

| Component | Status | Role | Current public contract | Hard limits |
|---|---|---|---|---|
| MySQL | `closed_primary` / cerrado principal | primary structural store | provision, reset, layered baseline, snapshot restore, per-worker clone, `migration-contract` | requires valid DB env; `per_worker` is intra-suite only |
| PostgreSQL / `pgsql` | `partial_experimental` / parcial experimental | secondary partial store | basic runtime/provision/reset only where explicitly implemented | no closed snapshot restore; no closed per-worker clone; no closed `migration-contract`; not equivalent to MySQL |
| Redis | `auxiliary` / auxiliar | optional service | service may be available when the stack starts it | no core PHP structural store lifecycle; no baseline/snapshot/clone participation |
| Influx | `auxiliary_profiling` / auxiliar/perfilado | profiling/reporting service | profiling/reporting infrastructure where enabled | not a primary store driver; no seed/bootstrap structural lifecycle |

## Engine contract

### MySQL

MySQL is the only closed primary path in this phase.

Current contract:

- provision: supported
- reset: supported
- layered baseline: supported
- snapshot restore: supported
- per-worker clone: supported
- `migration-contract`: supported

Limits:

- The project must provide visible runtime DB configuration.
- Managed provision requires admin credentials.
- `TEST_DB_STRATEGY=per_worker` isolates workers inside one suite only.
- `per_worker` does not make multiple top-level runners safe against the same project/store.

### PostgreSQL / `pgsql`

PostgreSQL is partial and experimental. It must not be advertised as MySQL-equivalent.

Current contract:

- provision: basic, only where explicitly implemented
- reset: basic, only where explicitly implemented
- layered baseline: not closed
- snapshot restore: not supported as a closed contract
- per-worker clone: not supported as a closed contract
- `migration-contract`: not supported as a closed contract

Limits:

- Do not use PostgreSQL as the closed snapshot/clone path.
- Do not use PostgreSQL for the closed `migration-contract` path in this phase.
- A visible `pg` compose service does not imply complete PostgreSQL support.

## Auxiliary services

### Redis

Redis is an auxiliary service, not a structural store driver owned by the core PHP lifecycle.

Current contract:

- may be started as part of `TESTKIT_STACK`
- may be used by the host project if that project owns the behavior
- does not participate in baseline, snapshot restore or per-worker clone

### Influx

Influx is auxiliary/profiling infrastructure.

Current contract:

- may be started as part of `TESTKIT_STACK`
- may be used for profiling/reporting where explicitly enabled
- does not participate in structural seed/bootstrap lifecycle
- is not a primary store driver

## DB strategy contract

| Strategy | Status | Scope | Contract |
|---|---|---|---|
| `shared` | supported | suite | Simple/sequential path. With DB-sensitive tests and `TEST_JOBS>1`, this becomes a visible risk. |
| `per_worker` | `supported_intra_suite` | suite workers | Derives isolated worker DB names inside one suite. It is not top-level parallel safe. |
| `clean` | `rejected_not_implemented` | none | Recognized but intentionally rejected. Use `shared` or `per_worker`. |

## Environment-facing contract

| Key | Supported values | Rejected values | Notes |
|---|---|---|---|
| `TEST_STORE_DRIVER` | `mysql`, `pgsql` | `redis`, `influx` | `mysql` is closed primary. `pgsql` is partial. Redis and Influx are not primary store drivers. |
| `DB_DRIVER` | `mysql`, `pgsql` | `redis`, `influx` | Runtime alias. It does not upgrade `pgsql` to closed snapshot/clone support. |
| `TEST_DB_STRATEGY` | `shared`, `per_worker` | `clean` | `clean` is known but not implemented. |
| `TEST_BASELINE_MODE` | `layered`, `snapshot` | — | `snapshot` is closed only in the MySQL path. |
| `TESTKIT_STACK` | `mysql`, `pg`, `redis`, `influx` | — | Stack entries describe services to start, not full framework support. |

## `migration-contract` path

`migration-contract` is a technical suite, not a general functional suite.

Closed requirements in this phase:

- engine: MySQL
- `TEST_BASELINE_MODE=snapshot`
- `TEST_DB_STRATEGY=shared`
- `TEST_JOBS=1`
- a resolvable snapshot source

PostgreSQL, Redis and Influx are outside the closed `migration-contract` path.

## Reading rule

A service being present in Docker Compose, a wrapper stack or an env example does not make it a closed lifecycle feature.

When this file, `docs/CONTRATO.md` and `ConfigSchema::inspectPayload()` disagree, treat that as a repository bug. Do not reinterpret partial support as full support.
