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

> Regla clave del kit (sin ambigüedades): **el env de tests es `.env.test`** y se busca en:
>
> 1) `root/test/.env.test` (preferido)
> 2) `root/.env.test` (soportado)

---


## Runners (nombres explícitos)

- `test/back/runTestBack.php` — runner PHP backend
- `test/front/runFrontTest.php` — runner PHP front
- `test/front/runFrontTest.mjs` — runner JS front
- `test/runTest.php` — meta-runner (orquesta los 3 anteriores)

Ejemplos:

```bash
# Backend (PHP)
php test/back/runTestBack.php

# Front (PHP)
php test/front/runFrontTest.php

# Front (JS)
node test/front/runFrontTest.mjs
```


## 1) Instalación (drop‑in)

1) Copiá la carpeta `test/` a la raíz del proyecto.

2) Creá el env de tests:

```bash
cp test/.env.test.example test/.env.test
```

> Alternativa soportada: crear `root/.env.test` (mismo formato).

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

Docs detallados (sin ambigüedades):

- `docs/quickstart_docker_linux.md`
- `docs/quickstart_docker_windows.md`
- `docs/quickstart_local_linux.md`
- `docs/quickstart_local_windows.md`

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

> Nota: las seeds que vienen son **templates/patrón**. En cada repo, ajustalas a tu schema real.

Docs: `docs/02_seeds.md`

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
- existe `test/.env.test` o `.env.test`
- Docker + daemon + compose

También avisa si los puertos configurados están ocupados (warning).

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

Docs: `docs/07_db_profiling.md`

---

## 7) Estructura y actualización del kit

Para que puedas **actualizar el kit** sin pisar tests del proyecto, el contrato recomendado es:

- Tests del proyecto:
  - `test/back/tests/**/*.test.php`
  - `test/front/tests/**/*.test.php`
  - `test/front/tests/**/*.test.mjs`

Cuando actualices el testkit en un repo, copiá todo `test/` **excepto**:

- `test/back/tests/`
- `test/front/tests/`

Doc: `docs/update_kit.md`

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
node test/front/runFrontTest.mjs
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

## Crédito / Autoría
Este proyecto está bajo licencia MIT. Si lo reutilizás o redistribuís, conservá el archivo LICENSE y el NOTICE.
Repo original: https://github.com/lucasborges2001/test

