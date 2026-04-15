# Uso operativo de testkit

## 1) Alcance de esta guía

Esta guía cubre la operación diaria:

- primer arranque
- comandos seguros
- diagnóstico inicial
- lectura correcta de fallos comunes

No redefine ownership ni contrato de plataforma. Para eso, leer [`CONTRATO.md`](CONTRATO.md). Para troubleshooting detallado, leer [`TROUBLESHOOTING.md`](TROUBLESHOOTING.md).

## 2) Quick start real

Supuestos mínimos:

- `testkit` está clonado como repo completo
- el proyecto bajo prueba existe en disco
- el env de tests vive dentro del repo del proyecto:
  - preferido: `<project>/test/.env.test`
  - aceptado: `<project>/.env.test`

### Linux/macOS

```bash
export TESTKIT_PROJECT_ROOT=/path/to/project

./bin/testkit doctor
./bin/testkit up -d
./bin/testkit run --rm testkit php runTest.php back-php
./bin/testkit inspect latest
```

### PowerShell

```powershell
$env:TESTKIT_PROJECT_ROOT = 'D:\Proyecto'

.\bin\testkit.ps1 doctor
.\bin\testkit.ps1 up -d
.\bin\testkit.ps1 run --rm testkit php runTest.php back-php
.\bin\testkit.ps1 inspect latest
```

Observaciones duras:

- el primer target no tiene que ser `all`; conviene empezar por una suite concreta
- el primer intento operativo seguro es secuencial
- `doctor` no reemplaza una corrida real; solo valida el contrato mínimo visible antes de entrar al runner

## 3) Secuencia segura para el primer diagnóstico

1. correr `doctor`
2. si falla, corregir el primer `[FAIL]`
3. levantar el stack con `up -d`
4. correr una sola suite con la ruta simple:
   - `TEST_JOBS=1`
   - `TEST_DB_STRATEGY=shared`
5. recién después probar filtros, `per_worker`, snapshot o suites agregadas

Ejemplo seguro:

```bash
TEST_JOBS=1 TEST_DB_STRATEGY=shared ./bin/testkit run --rm testkit php runTest.php back-php
```

Ejemplo peligroso:

```bash
./bin/testkit run --rm testkit php runTest.php back-php &
./bin/testkit run --rm testkit php runTest.php back-php &
```

Eso no es un uso soportado del store compartido.

## 4) Qué revisar primero cuando algo falla

### 4.1) `doctor`

Comando base:

```bash
./bin/testkit doctor
```

Dump útil de configuración efectiva:

```bash
./bin/testkit doctor --dump
```

Qué te dice de forma confiable:

- si encontró el env
- si el env quedó dentro del repo montado
- qué `TESTKIT_STACK` va a usar
- si `TESTKIT_ROOT` parece un repo completo
- si `TESTKIT_PROJECT_ROOT` existe
- si faltan variables mínimas del store
- si Docker está en PATH

Qué no te garantiza:

- que el bootstrap estructural cierre
- que el snapshot sea restaurable
- que una suite concreta no tenga conflictos de concurrencia
- que el proyecto haya definido seeds, migraciones o tests correctos

### 4.2) `inspect`

Después de una corrida, `inspect` da rutas de diagnóstico más cortas que leer JSON a mano.

Última corrida conocida:

```bash
./bin/testkit inspect latest
```

Primera falla canónica:

```bash
./bin/testkit inspect failure
```

Estado de baseline y migraciones:

```bash
./bin/testkit inspect seed-state
```

Locks y política de concurrencia:

```bash
./bin/testkit inspect concurrency
```

Usarlo cuando ya existe una corrida. No sirve como reemplazo de `doctor`.

## 5) Comandos operativos y lectura correcta

| Necesidad | Comando | Lectura correcta |
|---|---|---|
| validar setup mínimo | `doctor` | contrato mínimo del wrapper, no del baseline completo |
| ver configuración efectiva | `doctor --dump` | cómo quedó resuelto env/root/stack |
| levantar servicios | `up -d` | `docker compose` del stack elegido |
| correr una suite concreta | `php runTest.php back-php` | una sola suite, no todo el proyecto |
| correr resumen agregado | `php runTest.php all` | varias suites bajo un solo runner top-level |
| ver última corrida | `inspect latest` | resumen canónico de la última corrida resuelta |
| ver primera falla | `inspect failure` | lectura rápida del primer problema canónico |
| ver baseline/migración | `inspect seed-state` | útil para snapshot y `migration-contract` |
| ver locks/concurrencia | `inspect concurrency` | útil para contention o dudas sobre `per_worker` |
| leer reporte humano consolidado | `php scripts/report.php` | salida para personas, no contrato estable de automatización |

## 6) Qué comportamiento sí es esperado

### `no_tests`

Esperado cuando:

- la suite existe, pero los filtros no dejan nada
- `TEST_SCOPE`, `TEST_CATEGORY` o `TEST_MATCH` quedaron demasiado restrictivos

Qué revisar:

```bash
./bin/testkit inspect latest
```

y después afinar filtros.

No asumir:

- que significa bug del runner
- que significa que no hay archivos de tests en el repo

### `all_skipped`

Esperado cuando:

- la selección entró
- los tests decidieron skip en runtime

Qué revisar:

- condiciones de skip del proyecto
- dependencias o prerequisitos declarados por los tests

No confundir con:

- `no_tests`
- `bootstrap_error`

### contention / locks

Esperado cuando:

- ya hay otra corrida top-level usando el mismo store base
- se intentó throughput lanzando dos `runTest.php` sobre el mismo proyecto/store

Qué revisar:

```bash
./bin/testkit inspect concurrency
```

No tratarlo como bug por defecto. Es la defensa normal contra uso concurrente no soportado.

### `TEST_DB_STRATEGY=clean` rechazado

Esperado. No existe como modo operativo soportado.

### `migration-contract` rechazado fuera de snapshot/shared/MySQL

Esperado. No es una suite general; es un gate técnico acotado.

## 7) Qué comportamiento indica contrato roto o bug probable

Tomarlo como sospechoso si pasa cualquiera de estos casos:

- `doctor` dice que encontró un env válido y luego el wrapper vuelve a decir que no existe o que quedó fuera del repo
- un target documentado como válido es rechazado como inválido
- una corrida simple y secuencial (`TEST_JOBS=1`, `TEST_DB_STRATEGY=shared`) entra en errores de paralelismo sin que exista otra corrida
- `inspect latest` no puede leer reportes canónicos después de una corrida que sí escribió artifacts
- `doctor` o el wrapper sugieren paths de ejemplo que no existen en el repo
- el runner intenta vender como “parallel-safe” un modelo que sigue compitiendo por el mismo store base

En esos casos, ya no estás solo ante un mal setup; hay una desalineación entre contrato y comportamiento.

## 8) Cosas que no conviene sobreinterpretar

- `TEST_LIST=1` imprime selección, pero no debe venderse como dry-run puro del lifecycle de store; según la suite, el bootstrap puede ocurrir igual.
- `doctor` no prueba restore de snapshot ni migraciones.
- `scripts/report.php` no es el contrato estable para automatización.
- `per_worker` no habilita varios runners top-level en paralelo.
- `clone-per-worker` no aísla filesystem, colas, APIs ni side effects externos.

## 9) Patrones seguros vs peligrosos

| Caso | Estado |
|---|---|
| una sola corrida top-level, secuencial, `shared` | seguro |
| una sola corrida top-level, `per_worker`, `TEST_JOBS>1` | seguro si la suite necesita aislamiento DB intra-suite |
| una sola corrida top-level, `migration-contract` sobre snapshot MySQL | seguro |
| dos corridas top-level sobre el mismo store base | no soportado |
| usar `TEST_LIST=1` como si evitara todo bootstrap | lectura incorrecta |
| usar `all` como primer diagnóstico | posible, pero innecesariamente ruidoso |
| usar `back-php` o `front-js` como primer diagnóstico | recomendado |

## 10) Qué leer después

- [`TROUBLESHOOTING.md`](TROUBLESHOOTING.md) para síntomas concretos y pasos de corrección
- [`ARQUITECTURA.md`](ARQUITECTURA.md) para locks, bootstrap, baseline y concurrencia
- [`REPORTING_COVERAGE.md`](REPORTING_COVERAGE.md) para lectura de reportes y diagnostics
