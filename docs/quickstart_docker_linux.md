# Quickstart — Docker (Linux/macOS)

## 0) Pre-requisitos

- Docker + Docker Compose v2
- (Opcional) `make`

## 1) Preparar env

Desde el **root del repo**:

```bash
cp test/.env.test.example test/.env.test
```

Editar `test/.env.test` (DB, puertos, etc.).

> Alternativa soportada: crear `root/.env.test`.

## 2) Levantar stack + seeds + tests

```bash
cd test
chmod +x bin/testkit scripts/*.sh || true
./bin/testkit doctor
./bin/testkit up -d
./scripts/seed.sh
./bin/testkit run --rm testkit php runTest.php
```

## 3) Targets

```bash
./bin/testkit run --rm testkit php runTest.php back
./bin/testkit run --rm testkit php runTest.php front
./bin/testkit run --rm testkit php runTest.php front-php
./bin/testkit run --rm testkit php runTest.php front-js
```

## 4) Postgres (opcional)

```bash
cd test
./bin/testkit --pg up -d
./scripts/seed.sh
./bin/testkit run --rm testkit php runTest.php
```

## 5) Apagar / reset

```bash
cd test
./bin/testkit down
# reset total (borra volúmenes)
./scripts/db_reset.sh
```
