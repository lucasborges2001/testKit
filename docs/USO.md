# Uso operativo de testkit

## Regla principal

El runner público usa exactamente un selector tipado:

```text
--suite
--group
--category
```

No usar targets posicionales ni aliases públicos.

La referencia de nombres válidos es [`CONTRACT_REGISTRY.md`](CONTRACT_REGISTRY.md).

## Primer diagnóstico

Usar una suite concreta antes de agregados amplios.

```bash
export TESTKIT_PROJECT_ROOT=/ruta/al/proyecto

./bin/testkit doctor --compact
./bin/testkit inspect config-schema --json
./bin/testkit run --rm testkit php runTest.php --suite back-php --list
./bin/testkit run --rm testkit php runTest.php --suite back-php
./bin/testkit inspect latest
```

PowerShell:

```powershell
$env:TESTKIT_PROJECT_ROOT = 'D:\Proyecto'

.\bin\testkit.ps1 doctor --readonly --suite back-php --compact
.\bin\testkit.ps1 run --rm testkit php runTest.php --suite back-php --list
.\bin\testkit.ps1 run --rm testkit php runTest.php --suite back-php
.\bin\testkit.ps1 inspect latest
```

## Selectores

### Suites

```text
back-php
back-python
front-php
front-js
infra-php
migration-contract
reference-contract
sql-observability
```

### Grupos

```text
all
back
front
infra
php
js
```

### Categorías

```text
smoke
perf
stress
contract
critical
security
slow
```

Ejemplos:

```bash
php runTest.php --suite front-js
php runTest.php --group back
php runTest.php --category security
```

## Seleccionar archivos concretos

### Un archivo

```bash
php runTest.php --suite back-php \
  --test test/back/auth/login.test.php
```

### Varios archivos

`--test` es repetible:

```bash
php runTest.php --suite front-php \
  --test test/front/cliente/unit/cliente_api_wiring.test.php \
  --test test/front/cliente/unit/cliente_modal_contract.test.php
```

### Archivo de selección

```bash
mkdir -p .testkit
cat > .testkit/selection.front_php.txt <<'EOF'
test/front/cliente/unit/cliente_api_wiring.test.php
test/front/cliente/unit/cliente_modal_contract.test.php
EOF

php runTest.php --suite front-php \
  --selection-file .testkit/selection.front_php.txt
```

Reglas:

- rutas repo-relative;
- sin `..`;
- sin rutas absolutas;
- `--test` y `--selection-file` son mutuamente excluyentes.

Las variables `TEST_MATCH`, `TEST_MATCH_LIST`, `TEST_MATCH_FILE`, `TEST_MATCH_LIST_MODE` y `TEST_SELECTION_MATCH_MODE` no deben usarse como API pública nueva. Todavía existen bridges internos pendientes de eliminación en I4.

## `infra-php`

Usar para pruebas operacionales del host:

- HTTP real;
- Docker;
- seguridad operacional;
- cookies/autenticación;
- rutas reales;
- checks de infraestructura.

Ejemplos:

```bash
php runTest.php --suite infra-php
php runTest.php --suite infra-php --list
php runTest.php --category security
```

Config recomendada:

```env
TK_INFRA_PHP_TEST_ROOTS=test/infra
TK_INFRA_PHP_TEST_PATTERNS=*.test.php
TK_INFRA_PHP_TEST_EXCLUDE_ROOTS=
TK_INFRA_PHP_TEST_EXCLUDE_PATTERNS=*/vendor/*,*/node_modules/*,*/_out/*,*/.testkit/*,*/testkit/*
```

No usar aliases históricos como `infra` o `http`.

## `reference-contract`

Ejecutar con:

```bash
php runTest.php --suite reference-contract
```

No usar aliases históricos como `references` o `php-references`.

La suite analiza includes PHP estáticos/resolubles y no toca store.

Variables principales:

```env
TESTKIT_REFERENCE_SCOPE=back
TESTKIT_REFERENCE_ROOT=
TESTKIT_REFERENCE_TIMEOUT_SEC=20
TESTKIT_REFERENCE_MAX_FILES=3000
TESTKIT_REFERENCE_MAX_BYTES_PER_FILE=1048576
TESTKIT_REFERENCE_MAX_VIOLATIONS=200
TESTKIT_REFERENCE_DYNAMIC_SEVERITY=warn
```

## Proyectos sin store

```env
TEST_STORE_DRIVER=none
TEST_STORE_PROVISION=external
```

En esta modalidad no inventar credenciales MySQL para satisfacer checks.

## Store con MySQL

La ruta principal cerrada usa:

```env
TEST_STORE_DRIVER=mysql
```

Si el proyecto usa provisionado administrado debe declarar también las credenciales requeridas por ese contrato.

`TEST_STORE_DRIVER` es el único selector estructural. No inferirlo desde `DB_DRIVER`, DSN, credenciales o `TESTKIT_STACK`.

## Concurrencia

- `TEST_DB_STRATEGY=shared` es la ruta simple/secuencial.
- `TEST_DB_STRATEGY=per_worker` aísla workers dentro de una suite.
- `per_worker` no habilita varios runners top-level concurrentes sobre la misma DB base.
- `TEST_DB_STRATEGY=clean` no está implementado y debe rechazarse.

## Stack

`TESTKIT_STACK` describe servicios, no el store estructural.

Usar nombres canónicos:

```text
mysql
pg
redis
influx
```

No usar `postgres`, `postgresql` ni `influxdb` como configuración nueva aunque el runtime todavía los normalice; esa compatibilidad es deuda I3.

## Coverage

Activación típica:

```bash
TEST_COVERAGE=1 \
TEST_COVERAGE_FORMAT=both \
TEST_COVERAGE_SOURCE_DIRS='back,public_html' \
php runTest.php --suite back-php
```

Root canónico:

```env
TEST_COVERAGE_ROOT=.testkit/coverage
```

Artifacts esperados:

```text
.testkit/coverage/back_php
.testkit/coverage/front_php
.testkit/coverage/back_python
```

No usar `TEST_COVERAGE_DIR` en configuración nueva. El runtime todavía conserva esa compatibilidad y paths legacy bajo `test/coverage/`; su eliminación pertenece a I5.

## Rerun aislado

`TEST_RERUN_FAILED_ISOLATED=1` puede reejecutar fallidos de forma aislada para triage. No cambia el exit code de una corrida batch fallida y no debe ocultar flakiness.

Ejemplo:

```bash
TEST_RERUN_FAILED_ISOLATED=1 \
php runTest.php --suite front-php \
  --selection-file .testkit/selection.front_php.txt
```

## Browser runner

El wrapper selecciona la imagen browser cuando la operación incluye `front-js`, por ejemplo:

```bash
./bin/testkit run --rm testkit php runTest.php --suite front-js
./bin/testkit run --rm testkit php runTest.php --group front
```

Las suites que no requieren navegador usan el runner core.

## Build

Una corrida normal reutiliza la imagen existente. El rebuild explícito se solicita con:

```env
TESTKIT_RUN_BUILD=1
```

La falta de Buildx puede degradar rendimiento, pero no convierte por sí sola una corrida en inválida.

## Reporting

La consola es para operadores. Para automatización y agentes, preferir JSON/reportes persistidos.

Comandos de inspección:

```bash
./bin/testkit inspect latest
./bin/testkit inspect config-schema --json
php scripts/contract.php --json
php scripts/contract.php validate --json
```

No interpretar output truncado, `SKIP`, `WARN`, `UNKNOWN` o ausencia de ejecución como `PASS` contractual.
