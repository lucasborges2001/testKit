# TestKit (drop‑in) — PHP + JS

Este directorio `test/` está pensado para **copiarse tal cual** a cualquier repo que tenga:

- `back/`
- `public_html/`

y te deja un entorno de tests reproducible con:

- **Meta‑runner** `test/runTest.php` (orquesta back/front sin mezclar discovery)
- Runner **BACK/PHP** y **PUBLIC_HTML/PHP**
- Runner **PUBLIC_HTML/JS** (Node ESM + loader opcional hacia `public_html/`)
- **Coverage** PHP por test (Xdebug) → `lcov`/`json`
- **Seeds** listos (MySQL default, Postgres opcional)
- Interfaz de terminal (`make`, scripts y wrappers)

> Regla clave del kit: **el `.env` de tests vive en `test/.env.test`**.

---

## 1) Instalación (drop‑in)

1) Copiá la carpeta `test/` a la raíz del proyecto.

2) Creá el env de tests:

```bash
cp test/.env.test.example test/.env.test
```

3) Ajustá credenciales/variables para tu proyecto dentro de `test/.env.test`.

---

## 2) Requisitos

- Docker + Docker Compose v2
- (Opcional) `make` si querés usar el Makefile

Si corrés tests **sin Docker** (local):
- PHP 8.4+
- Node 18+ (recomendado 20)

---

## 3) Quick start (con Docker)

### Linux/macOS (bash)

```bash
cd test
./bin/testkit up -d
./bin/testkit run --rm testkit php runTest.php
```

### Windows (PowerShell)

```powershell
cd test
.\bin\testkit.ps1 up -d
.\bin\testkit.ps1 run --rm testkit php runTest.php
```

---

## 4) Targets típicos

### Correr todo

```bash
cd test
./bin/testkit run --rm testkit php runTest.php
```

### Solo BACK o solo FRONT

```bash
./bin/testkit run --rm testkit php runTest.php back
./bin/testkit run --rm testkit php runTest.php front
```

### Listar tests

```bash
./bin/testkit run --rm -e TEST_LIST=1 testkit php runTest.php back
```

### Filtrar por scope

```bash
./bin/testkit run --rm -e TEST_SCOPE=unit testkit php runTest.php
./bin/testkit run --rm -e TEST_SCOPE=integration testkit php runTest.php
```

### Filtrar por substring

```bash
./bin/testkit run --rm -e TEST_MATCH=usuario testkit php runTest.php back
```

---

## 5) Coverage

Coverage se activa con `TEST_COVERAGE=1` (y Xdebug ya está en el contenedor).

```bash
./bin/testkit run --rm \
  -e TEST_COVERAGE=1 \
  -e TEST_COVERAGE_FORMAT=lcov \
  testkit php runTest.php back
```

Salida por defecto:

- `test/_out/coverage/php_back/lcov.info`
- `test/_out/coverage/php_public/…`

> Tip: si querés forzar coverage desde PHP CLI además del env, podés setear `XDEBUG_MODE=coverage`.

---

## 6) Seeds (MySQL y Postgres)

- MySQL corre siempre.
- Postgres es opcional.

### MySQL (default)

```bash
cd test
./bin/testkit up -d
./scripts/seed.sh
```

### Activar Postgres

```bash
cd test
./bin/testkit --pg up -d
./scripts/seed.sh
```

Convención:
- `test/seeds/mysql/*.sql` se ejecuta en orden alfabético.
- `test/seeds/pgsql/*.sql` idem.

---

## 6.1) Doctor (validación del repo)

Antes de correr, recomendación:

```bash
cd test
./bin/testkit doctor
```

Chequea:
- existe `back/`
- existe `public_html/`
- existe `test/.env.test`
- Docker + daemon + compose

---

## 6.2) Timing (medición de tiempo)

- El meta-runner (`test/runTest.php`) imprime `time_ms` por suite y total.
- `t_run_cli()` (si lo usás en archivos individuales) imprime:
  - tiempo total del archivo
  - **Top N** casos más lentos (`TEST_TIME_TOP`, default 10)

---

## 6.3) Profiling de queries (para decidir índices)

Tu objetivo (“cuántas veces se pide tal dato / tal tabla”) tiene 2 capas:

### A) Profiling a nivel app (portable, recomendado)

Este kit incluye `test/utils/php/db_profiler.php`.

1) Activá profiling en `test/.env.test`:

```env
TEST_DB_PROFILE=1
```

2) En tus tests que pegan a DB, creá PDO con `tk_pdo()` (en vez de `new PDO(...)`):

```php
require_once __DIR__ . '/../utils/php/db_profiler.php';
$db = tk_pdo(); // devuelve PDO o PDO profilado según TEST_DB_PROFILE
```

3) Corré tus tests normalmente.

4) Generá reporte:

```bash
php test/scripts/query_report.php
```

El reporte te muestra:
- Top tablas por hits
- Top columnas `table.col` (heurístico)
- Top queries más lentas

**Importante:** el parseo SQL es best-effort (regex). Sirve para detectar patrones, no como “verdad absoluta”.

### B) MySQL Performance Schema (más exacto en SQL, menos portable)

Si querés ver *digests* y estadísticas de statements desde MySQL, podés usar `performance_schema`.
MySQL 8 suele traerlo habilitado, pero para métricas útiles a veces necesitás habilitar consumers.

Sugerencia práctica:
- Usá primero el profiling a nivel app (A)
- Y cruzalo con `EXPLAIN` / `EXPLAIN ANALYZE` en las queries lentas.

---

---

## 7) Correr sin Docker (local)

Si querés correr con tu PHP local:

```bash
DB_ENV_PATH=test/.env.test php test/runTest.php
```

Para JS, necesitás `node` disponible:

```bash
node test/front/runTestFront.mjs
```

---

## 8) Ajustar PHP/extensiones

El contenedor está en `test/docker/Dockerfile`.

- Para agregar extensiones, editá la sección `docker-php-ext-install …`.
- Para agregar paquetes del sistema, editá el `apt-get install …`.

---

## 9) Convenciones de repo

Este kit asume:

- `back/` existe (backend)
- `public_html/` existe (front)

Si en algún proyecto cambia eso: agregá un wrapper o flags nuevos, pero mantené el contrato.


### Profiling de DB y candidatos de índice

- Activar profiling: `TEST_DB_PROFILE=1`
- Reporte: `./bin/testkit run --rm testkit php scripts/query_report.php`

El reporte incluye **Top WHERE**, **Top JOIN/ON** y **Combos** (candidatos de índice compuesto) para ayudarte a priorizar qué revisar con `EXPLAIN`.
