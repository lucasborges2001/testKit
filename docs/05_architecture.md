# 05 — Arquitectura del TestKit

## Objetivo

- Un entorno **reutilizable** que se copia como `root/test/`.
- Runtime reproducible con Docker.
- Orquestación clara: **un punto de entrada** (`runTest.php`).

## Componentes

### 1) `bin/testkit`
Wrapper para ejecutar Docker Compose de forma segura:

- fuerza `--env-file test/.env.test`
- evita que Compose tome `.env` de otro lado
- permite activar Postgres con `--pg`

### 2) `compose.yaml` + `compose.pg.yaml`

- `compose.yaml`: MySQL + contenedor runner `testkit`
- `compose.pg.yaml`: agrega Postgres (solo cuando lo pedís)

### 3) `docker/Dockerfile`

Contenedor runner con:

- PHP (8.4 por defecto)
- Xdebug (coverage)
- Node (tests JS)
- clientes `mysql` y `psql` para seeds

### 4) `runTest.php`

Meta‑runner. Responsabilidades:

- descubrir tests
- ejecutar back/front
- aplicar filtros (`TEST_SCOPE`, `TEST_MATCH`)
- consolidar salida y exit codes

### 5) `scripts/seed.sh`

Contrato de seeds:

- `seeds/mysql/*.sql` en orden alfabético
- `seeds/pgsql/*.sql` en orden alfabético
- Postgres solo corre si está activo

## Salidas

- Artefactos en `test/_out/`
- Coverage en `test/_out/coverage/...`
