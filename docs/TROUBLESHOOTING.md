# Troubleshooting operativo

## Síntomas cubiertos en este corte

### 1) `doctor` marca `AGGREGATE_TARGET_NOISY_FIRST_DIAG`

Lectura correcta:

- el target es válido
- el problema no es invalidez, sino ruido diagnóstico

Qué hacer:

- bajar a una suite concreta (`back-php`, `front-js`, etc.)

### 2) `doctor` marca `TARGET_CATEGORY_MISMATCH`

Lectura correcta:

- pediste un target por categoría
- pero también dejaste `TEST_CATEGORY` con otro valor visible

Qué hacer:

- quitar `TEST_CATEGORY`
- o alinearlo con el target pedido

### 3) `doctor` marca `MULTIWORKER_SHARED_VISIBLE_RISK`

Lectura correcta:

- `TEST_JOBS>1` con `shared` no queda cerrado como ruta segura

Qué hacer:

- volver a `TEST_JOBS=1`
- o usar `TEST_DB_STRATEGY=per_worker`

### 4) `doctor` marca `PER_WORKER_SINGLE_WORKER_OVERCONFIGURED`

Lectura correcta:

- no es contradicción dura
- pero agrega complejidad sin ganancia visible

Qué hacer:

- simplificar a `shared` si no necesitás workers múltiples

### 5) `doctor` marca `POSTGRES_PARTIAL_SUPPORT`

Lectura correcta:

- PostgreSQL no está fallando por existir
- pero no tiene contrato cerrado equivalente a MySQL para snapshot/clone/per_worker clone

Qué hacer:

- si necesitás `migration-contract`, usá MySQL
- si necesitás cerrar PostgreSQL, registralo como feature futura y no lo trates como PASS contractual

### 6) `doctor` marca `REDIS_NOT_STRUCTURAL_STORE` o `INFLUX_NOT_STRUCTURAL_STORE`

Lectura correcta:

- configuraste Redis o Influx como store driver principal
- eso contradice el contrato actual

Qué hacer:

- dejalos en `TESTKIT_STACK` como servicios auxiliares si los necesitás
- declarÁ `TEST_STORE_DRIVER=mysql`, `TEST_STORE_DRIVER=pgsql` o `TEST_STORE_DRIVER=none` según corresponda

`DB_DRIVER`, `TEST_DB_DRIVER`, DSN, credenciales y `TESTKIT_STACK` no seleccionan el store.

### 7) `doctor` marca `TEST_STORE_DRIVER_REQUIRED`

Lectura correcta:

- el env de tests no declara el selector canónico de store
- testKit no infiere MySQL ni PostgreSQL desde otras variables

Qué hacer:

- declarar exactamente uno de:
  - `TEST_STORE_DRIVER=mysql`
  - `TEST_STORE_DRIVER=pgsql`
  - `TEST_STORE_DRIVER=none`

### 8) `doctor` marca `TEST_STORE_DRIVER_INVALID`

Lectura correcta:

- `TEST_STORE_DRIVER` existe, pero no coincide exactamente con un valor soportado
- no se normalizan `pg`, `postgres`, `postgresql`, mayúsculas ni espacios

Qué hacer:

- usar exactamente `mysql`, `pgsql` o `none`

### 9) `doctor` marca `CLEAN_STRATEGY_UNSUPPORTED`

Lectura correcta:

- `TEST_DB_STRATEGY=clean` está reconocido, pero no implementado como modo operativo de suite

Qué hacer:

- usar `shared` o `per_worker`

### 10) Necesito ver el esquema soportado real

Usá:

```bash
./bin/testkit inspect config-schema --json
```
