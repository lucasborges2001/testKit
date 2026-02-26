# test/scripts

Scripts de testing **separados por plataforma** y **por runtime** (local vs docker).

En la raiz de `test/scripts/` solo hay documentacion y archivos compartidos. Para ejecutar, entra a la carpeta que te corresponde.

---

## 1) Windows (PowerShell)

### LOCAL (XAMPP / MySQL + PHP locales)

Carpeta: `test/scripts/win/local/`

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .	est\scripts\win\local\db_reset.ps1 dropdb
powershell -NoProfile -ExecutionPolicy Bypass -File .	est\scripts\win\local\seed.ps1
powershell -NoProfile -ExecutionPolicy Bypass -File .	est\scripts\win\local	est.ps1 back
```

### DOCKER (TestKit)

Carpeta: `test/scripts/win/docker/`

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .	est\scripts\win\docker\db_reset.ps1 heavy
powershell -NoProfile -ExecutionPolicy Bypass -File .	est\scripts\win\docker\seed.ps1
powershell -NoProfile -ExecutionPolicy Bypass -File .	est\scripts\win\docker	est.ps1 back
```

---

## 2) Linux / Mac (bash)

### LOCAL

Carpeta: `test/scripts/unix/local/`

```bash
bash ./test/scripts/unix/local/db_reset.sh dropdb
bash ./test/scripts/unix/local/seed.sh
bash ./test/scripts/unix/local/test.sh back
```

### DOCKER (TestKit)

Carpeta: `test/scripts/unix/docker/`

```bash
bash ./test/scripts/unix/docker/db_reset.sh heavy
bash ./test/scripts/unix/docker/seed.sh
bash ./test/scripts/unix/docker/test.sh back
```

---

## 3) Reset modes

Todos los `db_reset` aceptan:

- `heavy`
  - **Docker**: `testkit down -v` + `up -d` (resetea volumenes)
  - **Local**: equivale a `dropdb`
- `dropdb` (**recomendado**): `DROP DATABASE` + `CREATE DATABASE`
- `fast`: `TRUNCATE` tablas (rapido; requiere schema ya aplicado)

Si no pasas el argumento, se toma `TEST_RESET_MODE` o `dropdb`.

---

## 4) Targets (meta-runner)

`test.ps1` / `test.sh` ejecutan:

```
test/runTest.php <target>
```

Targets tipicos:

- `all`
- `back`
- `front`
- `front-php`
- `front-js`
- `php`
- `js`

---

## 5) Variables de entorno

Se leen desde `test/.env.test` (preferido) o `<repo>/.env.test`.

### Comunes

- `TEST_DB_STRATEGY=shared|per_worker`
- `TEST_JOBS=1..N` (solo `per_worker`)
- `TEST_MYSQL_DB=app_test`
- `TEST_DB_WORKER_SUFFIX_FORMAT=_w%02d`
- `TEST_RESET_MODE=heavy|dropdb|fast`

### Local (MySQL/PHP)

Binarios:

- `MYSQL_BIN` (Windows default `C:/xampp/mysql/bin/mysql.exe`, unix default `mysql`)
- `PHP_BIN`   (Windows default `C:/xampp/php/php.exe`, unix default `php`)

Conexión MySQL:

- `DB_HOST` (default `127.0.0.1`)
- `DB_PORT` (default `3306`)
- `DB_NAME` (si no esta, usa `TEST_MYSQL_DB`)
- `DB_USER` (default `root`)
- `DB_PASS` (default vacio)

### Docker (TestKit)

- `TESTKIT_BIN` (override para `bin/testkit` / `bin/testkit.ps1`)
- `TESTKIT_ENV_FILE` (override del env de tests)

---

## 6) Layout de SQL

- Schema (opcional): `test/schema/mysql/*.sql`
- Seeds (fixtures):  `test/seeds/mysql/*.sql`

Se aplican en orden alfabetico.

Mas detalle en `seeds.md`.

---

## 7) Extras

- `test/scripts/unix/mk_env_test.sh`: helper para generar `test/.env.test`
- `test/scripts/common/query_report.php`: reporte de profiling de queries
