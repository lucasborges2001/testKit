# Uso operativo de testkit

## 1) Qué responde esta guía

Usar esta guía para la operación normal:

- primer arranque
- comandos base
- secuencia segura de ejecución
- lectura operativa mínima de lo que pasó
- lectura humana de observabilidad durante una corrida larga
- cómo usar `doctor` en modo `full` o `compact`

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

./bin/testkit doctor --compact
./bin/testkit doctor --full migration-contract
./bin/testkit up -d
./bin/testkit run --rm testkit php runTest.php back-php
./bin/testkit inspect latest
```

### PowerShell

```powershell
$env:TESTKIT_PROJECT_ROOT = 'D:\Proyecto'

.\bin\testkit.ps1 doctor --compact
.\bin\testkit.ps1 doctor --full migration-contract
.\bin\testkit.ps1 up -d
.\bin\testkit.ps1 run --rm testkit php runTest.php back-php
.\bin\testkit.ps1 inspect latest
```

### PowerShell: nota de alcance

Este cambio de doctor no redefine el contrato general de `run` en PowerShell.

Para inyectar variables al contenedor de forma soportada por el wrapper, seguir usando flags `-e`, por ejemplo:

```powershell
.\bin\testkit.ps1 run --rm -e TEST_MATCH=alerta testkit php runTest.php back-php
```

No mezclar mejoras de `doctor` con promesas nuevas sobre parsing de runtime que este zip no toca.

Observaciones duras:

- el primer target no tiene que ser `all`; conviene empezar por una suite concreta
- el primer intento operativo seguro es secuencial
- `doctor` no reemplaza una corrida real; valida contrato mínimo visible y, cuando corresponde, compatibilidad contractual visible antes de entrar al runner
- el capability doctor no prueba bootstrap, restore ni seguridad runtime de concurrencia top-level
- `compact` no cambia checks; solo cambia densidad de salida

## 3) Secuencia segura para el primer diagnóstico

1. correr `doctor --compact`
2. si necesitás detalle fino o vas a trabajar sobre el wrapper, correr `doctor --full`
3. si necesitás validar un contrato más angosto, correr `doctor --full migration-contract`
4. si falla, corregir el primer `[FAIL]`
5. levantar el stack con `up -d`
6. correr una sola suite con la ruta simple:
   - `TEST_JOBS=1`
   - `TEST_DB_STRATEGY=shared`
7. recién después probar filtros, `per_worker`, snapshot o suites agregadas

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
| validar setup mínimo con poco ruido | `doctor --compact` | resumen operador-first; útil para loops repetidos |
| validar setup mínimo con detalle | `doctor --full` | contrato mínimo del wrapper + detalle por check |
| validar un path contractual angosto | `doctor --full migration-contract` | lectura visible por config del target pedido |
| ver configuración efectiva | `doctor --dump --full` | cómo quedó resuelto env/root/stack + checks serializados |
| ver configuración efectiva de un target | `doctor --dump --full migration-contract` | dump + status capability visible por config |
| levantar servicios | `up -d` | `docker compose` del stack elegido |
| correr una suite concreta | `php runTest.php back-php` | una sola suite, no todo el proyecto |
| correr resumen agregado | `php runTest.php all` | varias suites bajo un solo runner top-level |
| ver última corrida | `inspect latest` | resumen canónico de la última corrida resuelta |
| ver primera falla | `inspect failure` | lectura rápida del primer problema canónico |
| ver baseline/migración | `inspect seed-state` | útil para snapshot y `migration-contract` |
| ver locks/concurrencia | `inspect concurrency` | útil para contention o dudas sobre `per_worker` |
| leer reporte humano consolidado | `php scripts/report.php` | salida para personas, no contrato estable de automatización |

## 5) Cómo leer `doctor`

### 5.1) Dos modos de render

`doctor` tiene un solo set de checks, pero dos vistas:

- `--full`
- `--compact`

Reglas:

- si no declarás nada, el default es `full`
- `TESTKIT_DOCTOR_MODE=compact|full` puede fijar default de entorno
- `--compact` o `--full` en CLI pisan el env
- `--dump` funciona en ambos, pero operativamente tiene más sentido con `--full`

Ejemplos:

```bash
./bin/testkit doctor --compact
./bin/testkit doctor --full
./bin/testkit doctor --full migration-contract
./bin/testkit doctor --dump --full migration-contract
```

```powershell
.\bin\testkit.ps1 doctor --compact
.\bin\testkit.ps1 doctor --full
.\bin\testkit.ps1 doctor --full migration-contract
.\bin\testkit.ps1 doctor --dump --full migration-contract
```

### 5.2) Qué muestra `full`

`full` muestra:

- bloque `== TESTKIT DOCTOR ==`
- contexto (`mode`, `target`, `stack`, roots)
- sección `== BASE CHECKS ==`
- sección `== CAPABILITY DOCTOR ==` cuando aplica
- cada check con `status`, `code`, `summary` y opcionalmente `action`

Usarlo cuando:

- estás corrigiendo setup
- estás desarrollando el wrapper
- necesitás ver exactamente qué regla disparó `WARN`, `UNKNOWN` o `FAIL`

### 5.3) Qué muestra `compact`

`compact` muestra:

- contexto resumido (`mode`, `target`)
- agregados por estado para base y capability
- solo problemas relevantes:
  - `FAIL`
  - `WARN`
  - `UNKNOWN` en capability

No muestra por defecto todos los `PASS` individuales.

Usarlo cuando:

- querés menos ruido en terminal chica
- repetís `doctor` muchas veces durante iteración
- querés señal rápida sin leer toda la narrativa

### 5.4) Qué te dice de forma confiable

- si encontró el env
- si el env quedó dentro del repo montado
- qué `TESTKIT_STACK` va a usar
- si `TESTKIT_ROOT` parece un repo completo
- si `TESTKIT_PROJECT_ROOT` existe
- si faltan variables mínimas del store
- si Docker está en PATH
- si la config visible contradice reglas genéricas de estrategia/motor
- si `migration-contract` contradice snapshot/shared/MySQL cuando lo pedís explícitamente

### 5.5) Qué no te garantiza

- que el bootstrap estructural cierre
- que el snapshot sea restaurable
- que una suite concreta no tenga conflictos de concurrencia solo porque capability diga `PASS`
- que el proyecto haya definido seeds, migraciones o tests correctos
- que `Capability doctor: PASS` vuelva segura una ruta runtime no observada por `doctor`

### 5.6) Lectura correcta del capability block

- `PASS`: no ve contradicción visible en esa regla
- `WARN`: la config visible es rara o incompleta, pero no alcanza para marcar contradicción dura
- `UNKNOWN`: falta contexto observable para cerrar compatibilidad
- `FAIL`: hay contradicción contractual visible
- el exit code del wrapper sigue atado al doctor base, no al bloque capability

### 5.7) Dump útil

```bash
./bin/testkit doctor --dump --full
./bin/testkit doctor --dump --full migration-contract
```

El dump expone, entre otros:

- `TESTKIT_DOCTOR_MODE`
- `TESTKIT_DOCTOR_TARGET`
- `TESTKIT_DOCTOR_BASE_STATUS`
- `TESTKIT_CAPABILITY_STATUS`
- `TESTKIT_DOCTOR_BASE_CHECK_<n>_*`
- `TESTKIT_CAPABILITY_CHECK_<n>_*`

`compact` también puede usarse con dump, pero el objetivo del dump no es “compactar” sino serializar la lectura efectiva.

### 5.8) `inspect`

Después de una corrida, `inspect` da rutas de diagnóstico más cortas que leer JSON a mano.

```bash
./bin/testkit inspect latest
./bin/testkit inspect failure
./bin/testkit inspect seed-state
./bin/testkit inspect concurrency
```

Usarlo cuando ya existe una corrida. No reemplaza `doctor`.

## 6) Observabilidad de ejecución

Durante una suite larga, el runner puede emitir señales de progreso humano, advertencias de test largo y un resumen final de timings por fase.

Ejemplo por defecto (compacto):

```text
[Progress] el=00:03:41 done=23/267 p/f/s/to=22/1/0/0 eta=00:39:12 jobs=3
[Test] status=PASS done=24/267 dur=00:00:09 rel=test/back/.../BarTest.php worker=2
[WARN] long_running_test elapsed=00:01:00 rel=test/back/.../FooTest.php worker=1
[Phase Timings]
  discovery_ms=118
  admission_ms=41
  execution_ms=602311
  reporting_ms=352
```

Ejemplo verbose:

```text
[Progress] el=00:03:41 done=23/267 p/f/s/to=22/1/0/0 eta=00:39:12 cur=test/back/.../FooTest.php cur_el=00:00:37 avg=9.6s/test jobs=3 workers=w1:...@00:00:37, w2:...@00:00:11, w3:...@00:00:04
[Test] status=PASS done=24/267 dur=00:00:09 rel=test/back/.../BarTest.php worker=2 el=00:03:50 p/f/s/to=23/1/0/0 jobs=3 active=w1:...@00:00:46, w3:...@00:00:13
```

Además, cuando una suite falla, el reporte final prioriza una lectura operador-first al inicio del bloque de resultado:

- `Operator Summary` con `status`, `primary_problem`, `focus`, `next_action` y `report_root`
- menos eco entre primera falla, acción principal y comandos de rerun
- meta summary agregado con `focus_suite`, `focus_file` y hint para bajar a una suite concreta cuando corriste un target agregado con fallas

Lectura correcta:

- `[Progress]` es una señal humana de que la suite sigue viva; no es un formato para automatización
- el modo por defecto debe ser escaneable en terminal chica; si necesitás contexto fino, subí a `verbose`
- `workers=` resume workers activos cuando `TEST_JOBS > 1`; sirve para ver quién está ocupado y hace cuánto
- `[Test]` aparece solo en `per_test` y emite una línea por test completado
- `rel`, `cur`, `workers` y `active` pueden aparecer compactados o truncados con elipsis en terminales angostas; el contrato estable es el significado del campo, no el path raw completo
- `avg` y `eta` usan promedio simple de la corrida actual; no hay heurística histórica en esta capa
- `[WARN] long_running_test` es informativo; no cambia exit code ni estado final
- `[WARN] long_running_test` aplica tanto en `heartbeat` como en `per_test`; `quiet` lo suprime
- `[Phase Timings]` resume tiempos gruesos de `discovery`, `admission`, `execution` y `reporting`
- `Possible Flaky Tests (heuristic)` sigue siendo señal histórica; no es diagnóstico firme

Configuración mínima:

- `TESTKIT_PROGRESS_MODE=heartbeat|per_test|quiet`
- `TESTKIT_PROGRESS_DETAIL=compact|verbose` (default `compact`)
- `TESTKIT_PROGRESS_INTERVAL_SEC` (default `15`)
- `TESTKIT_LONG_TEST_WARN_SEC` (default `60`)

## 7) Qué comportamiento sí es esperado

| Situación | Lectura correcta |
|---|---|
| `suite_status=no_tests` | la selección quedó vacía; no implica bug por sí sola |
| `suite_status=all_skipped` | la selección entró, pero los tests decidieron skip en runtime |
| contention / locks | ya existe otra corrida top-level sobre el mismo store base |
| `TEST_DB_STRATEGY=clean` rechazado | comportamiento esperado; no es un modo soportado |
| `migration-contract` rechazado fuera de snapshot/shared/MySQL | comportamiento esperado; es una suite técnica acotada |
| `Capability doctor: UNKNOWN` | doctor no tiene evidencia visible suficiente para cerrar esa compatibilidad |
| `doctor --compact` muestra menos líneas que `--full` | esperado; cambia render, no semántica |

Para causas y pasos concretos, leer [`TROUBLESHOOTING.md`](TROUBLESHOOTING.md).

## 8) Cosas que no conviene sobreinterpretar

- `TEST_LIST=1` imprime selección, pero no debe venderse como dry-run puro del lifecycle de store; según la suite, el bootstrap puede ocurrir igual.
- `doctor` no prueba restore de snapshot ni migraciones.
- `doctor migration-contract` solo evalúa contradicciones visibles por config; no ejecuta restore ni seed-state.
- `doctor --compact` no implica menos checks; solo menos verbosidad.
- `scripts/report.php` no es el contrato estable para automatización.
- `per_worker` no habilita varios runners top-level en paralelo.
- `clone-per-worker` no aísla filesystem, colas, APIs ni side effects externos.
- `[Progress]` y `[Test]` no reemplazan `inspect`, el JSON persistido ni el reporte final.
- esta capa no persiste heartbeats individuales ni telemetría por evento.
- `Possible Flaky Tests (heuristic)` y familias de triage siguen siendo heurísticas; sirven para orientar, no para cerrar causa raíz.

## 9) Patrones seguros vs peligrosos

| Caso | Estado |
|---|---|
| una sola corrida top-level, secuencial, `shared` | seguro |
| una sola corrida top-level, `per_worker`, `TEST_JOBS>1` | seguro si la suite necesita aislamiento DB intra-suite |
| una sola corrida top-level, `migration-contract` sobre snapshot MySQL | seguro como ruta contractual, pero sigue requiriendo una corrida real |
| dos corridas top-level sobre el mismo store base | no soportado |
| usar `TEST_LIST=1` como si evitara todo bootstrap | lectura incorrecta |
| usar `all` como primer diagnóstico | posible, pero innecesariamente ruidoso |
| usar `back-php` o `front-js` como primer diagnóstico | recomendado |

## 10) Qué leer después

| Si tu pregunta es... | Leer |
|---|---|
| qué exige y qué no garantiza la plataforma | [`CONTRATO.md`](CONTRATO.md) |
| por qué apareció un error concreto y qué revisar | [`TROUBLESHOOTING.md`](TROUBLESHOOTING.md) |
| cómo funcionan bootstrap, baseline y locks | [`ARQUITECTURA.md`](ARQUITECTURA.md) |
| cómo leer reportes, estados, coverage y observabilidad persistida | [`REPORTING_COVERAGE.md`](REPORTING_COVERAGE.md) |
