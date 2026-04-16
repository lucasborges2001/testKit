# InfluxDB 2.7 en testkit

## Qué agrega esta propuesta

Esta integración **no** mete InfluxDB dentro de `StoreAdapter`.
Ese contrato hoy es relacional/PDO y fingir que Influx “es otra SQL DB” sería una mala abstracción.

La propuesta agrega una vía paralela y explícita:

- overlay `compose.influx.yaml`
- soporte de `TESTKIT_STACK=influx` o `mysql,redis,influx`
- helpers PHP bajo `core/php/influx/*`
- script `scripts/influx_router.php` para smoke y limpieza por `run_id`

## Qué sí queda soportado por esta vía

- levantar InfluxDB 2.7 junto al stack de tests
- validar health/ready
- asegurar bucket
- escribir line protocol
- consultar por Flux
- purgar datos de una corrida por tag (`testkit_run_id` por default)

## Qué NO se vende como soportado

- `StoreAdapter` para Influx
- `StoreMaintenance` estructural tipo MySQL
- snapshot restore contractual
- clone-per-worker contractual
- `migration-contract` sobre Influx
- equivalencia semántica con la ruta cerrada MySQL

## Variables mínimas

```env
TESTKIT_STACK=mysql,redis,influx

TEST_INFLUX_ORG=testkit
TEST_INFLUX_BUCKET=testkit
TEST_INFLUX_TOKEN=testkit-token
TEST_INFLUX_ADMIN_TOKEN=testkit-admin-token

# Opcionales
TEST_INFLUX_HOST=influx_test
TEST_INFLUX_INTERNAL_PORT=8086
TEST_INFLUX_PORT=38086
TEST_INFLUX_URL=
TEST_INFLUX_TIMEOUT_SEC=15
TEST_INFLUX_PRECISION=ns
TEST_INFLUX_RUN_TAG_KEY=testkit_run_id
```

## Comandos útiles

```bash
./bin/testkit up -d

./bin/testkit run --rm testkit php /workspace/testkit/scripts/influx_router.php health
./bin/testkit run --rm testkit php /workspace/testkit/scripts/influx_router.php bucket-ensure
./bin/testkit run --rm testkit php /workspace/testkit/scripts/influx_router.php write-smoke
./bin/testkit run --rm testkit php /workspace/testkit/scripts/influx_router.php query-smoke
./bin/testkit run --rm testkit php /workspace/testkit/scripts/influx_router.php purge-run
```

## Lectura correcta del diseño

Si un test del proyecto necesita Influx, esta ruta le da un servicio real y helpers honestos.
No intenta esconder que la plataforma principal de lifecycle estructural sigue cerrada en MySQL.
