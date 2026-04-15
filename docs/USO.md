# Uso operativo de testkit

## 1) Qué responde esta guía

Usar esta guía para la operación normal:

- primer arranque
- comandos base
- secuencia segura de ejecución
- lectura operativa mínima de lo que pasó

No usarla para:

- definir ownership y límites del contrato
- explicar la arquitectura interna
- troubleshooting síntoma por síntoma
- describir en detalle el contrato de reportes

Para eso, leer:

- [`CONTRATO.md`](CONTRATO.md)
- [`ARQUITECTURA.md`](ARQUITECTURA.md)
- [`TROUBLESHOOTING.md`](TROUBLESHOOTING.md)
- [`REPORTING_COVERAGE.md`](REPORTING_COVERAGE.md)

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

## 4) Comandos base

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

## 5) Lectura operativa mínima

### `doctor`

Comando base:

```bash
./bin/testkit doctor
```

Dump útil:

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

### `inspect`

Después de una corrida, `inspect` da rutas de diagnóstico más cortas que leer JSON a mano.

```bash
./bin/testkit inspect latest
./bin/testkit inspect failure
./bin/testkit inspect seed-state
./bin/testkit inspect concurrency
```

Usarlo cuando ya existe una corrida. No reemplaza `doctor`.

## 6) Qué comportamiento sí es esperado

| Situación | Lectura correcta |
|---|---|
| `suite_status=no_tests` | la selección quedó vacía; no implica bug por sí sola |
| `suite_status=all_skipped` | la selección entró, pero los tests decidieron skip en runtime |
| contention / locks | ya existe otra corrida top-level sobre el mismo store base |
| `TEST_DB_STRATEGY=clean` rechazado | comportamiento esperado; no es un modo soportado |
| `migration-contract` rechazado fuera de snapshot/shared/MySQL | comportamiento esperado; es una suite técnica acotada |

Para causas y pasos concretos, leer [`TROUBLESHOOTING.md`](TROUBLESHOOTING.md).

## 7) Cosas que no conviene sobreinterpretar

- `TEST_LIST=1` imprime selección, pero no debe venderse como dry-run puro del lifecycle de store; según la suite, el bootstrap puede ocurrir igual.
- `doctor` no prueba restore de snapshot ni migraciones.
- `scripts/report.php` no es el contrato estable para automatización.
- `per_worker` no habilita varios runners top-level en paralelo.
- `clone-per-worker` no aísla filesystem, colas, APIs ni side effects externos.

## 8) Patrones seguros vs peligrosos

| Caso | Estado |
|---|---|
| una sola corrida top-level, secuencial, `shared` | seguro |
| una sola corrida top-level, `per_worker`, `TEST_JOBS>1` | seguro si la suite necesita aislamiento DB intra-suite |
| una sola corrida top-level, `migration-contract` sobre snapshot MySQL | seguro |
| dos corridas top-level sobre el mismo store base | no soportado |
| usar `TEST_LIST=1` como si evitara todo bootstrap | lectura incorrecta |
| usar `all` como primer diagnóstico | posible, pero innecesariamente ruidoso |
| usar `back-php` o `front-js` como primer diagnóstico | recomendado |

## 9) Qué leer después

| Si tu pregunta es... | Leer |
|---|---|
| qué exige y qué no garantiza la plataforma | [`CONTRATO.md`](CONTRATO.md) |
| por qué apareció un error concreto y qué revisar | [`TROUBLESHOOTING.md`](TROUBLESHOOTING.md) |
| cómo funcionan bootstrap, baseline y locks | [`ARQUITECTURA.md`](ARQUITECTURA.md) |
| cómo leer reportes, estados y coverage | [`REPORTING_COVERAGE.md`](REPORTING_COVERAGE.md) |
