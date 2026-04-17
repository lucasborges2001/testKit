# Uso operativo de testkit

## 1) Qué responde esta guía

Usar esta guía para la operación normal:

- primer arranque
- comandos base
- secuencia segura de ejecución
- lectura operativa mínima de lo que pasó
- lectura humana de observabilidad durante una corrida larga

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
./bin/testkit doctor migration-contract
./bin/testkit up -d
./bin/testkit run --rm testkit php runTest.php back-php
./bin/testkit inspect latest
```

### PowerShell

```powershell
$env:TESTKIT_PROJECT_ROOT = 'D:\Proyecto'

.\bin\testkit.ps1 doctor
.\bin\testkit.ps1 doctor migration-contract
.\bin\testkit.ps1 up -d
.\bin\testkit.ps1 run --rm testkit php runTest.php back-php
.\bin\testkit.ps1 inspect latest
```

### PowerShell: filtros con variables inline dentro del contenedor

Cuando el filtro o la suite necesitan variables para el proceso que corre **dentro** del contenedor, usar una tail command única después de `testkit`:

```powershell
.in	estkit.ps1 run --rm testkit 'TEST_MATCH="alerta" php runTest.php back-php'
```

Lectura correcta:

- el wrapper detecta que la tail command fue pasada como un único string
- la ejecuta con `sh -lc` dentro del contenedor
- reescribe `runTest.php` a `/workspace/testkit/runTest.php`
- tolera también la forma con assignments inline antes del comando

No vender otra cosa:

- PowerShell no interpreta `TEST_MATCH="alerta" php ...` como bash
- si el wrapper no normaliza ese caso, Docker intenta ejecutar `TEST_MATCH="alerta"` como binario
- el modo más explícito y portable en PowerShell es pasar la tail command como string único

Observaciones duras:

- el primer target no tiene que ser `all`; conviene empezar por una suite concreta
- el primer intento operativo seguro es secuencial
- `doctor` no reemplaza una corrida real; valida contrato mínimo visible y, cuando corresponde, compatibilidad contractual visible antes de entrar al runner
- el capability doctor de este primer corte no prueba bootstrap, restore ni seguridad runtime de concurrencia top-level

## 3) Secuencia segura para el primer diagnóstico

1. correr `doctor`
2. si necesitás validar un contrato más angosto, correr `doctor migration-contract`
3. si falla, corregir el primer `[FAIL]`
4. levantar el stack con `up -d`
5. correr una sola suite con la ruta simple:
   - `TEST_JOBS=1`
   - `TEST_DB_STRATEGY=shared`
6. recién después probar filtros, `per_worker`, snapshot o suites agregadas

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
| validar un path contractual angosto | `doctor migration-contract` | lectura visible por config del target pedido |
| ver configuración efectiva | `doctor --dump` | cómo quedó resuelto env/root/stack |
| ver configuración efectiva de un target | `doctor --dump migration-contract` | dump + status capability visible por config |
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

Con target explícito:

```bash
./bin/testkit doctor migration-contract
```

Dump útil:

```bash
./bin/testkit doctor --dump
./bin/testkit doctor --dump migration-contract
```

Qué te dice de forma confiable:

- si encontró el env
- si el env quedó dentro del repo montado
- qué `TESTKIT_STACK` va a usar
- si `TESTKIT_ROOT` parece un repo completo
- si `TESTKIT_PROJECT_ROOT` existe
- si faltan variables mínimas del store
- si Docker está en PATH
- si la config visible contradice reglas genéricas de estrategia/motor
- si `migration-contract` contradice snapshot/shared/MySQL cuando lo pedís explícitamente

Qué no te garantiza:

- que el bootstrap estructural cierre
- que el snapshot sea restaurable
- que una suite concreta no tenga conflictos de concurrencia solo porque capability diga `PASS`
- que el proyecto haya definido seeds, migraciones o tests correctos
- que `Capability doctor: PASS` vuelva segura una ruta runtime no observada por `doctor`

Lectura correcta del nuevo bloque capability:

- `PASS`: no ve contradicción visible en esa regla
- `WARN`: la config visible es rara o incompleta, pero no alcanza para marcar contradicción dura
- `UNKNOWN`: falta contexto observable para cerrar compatibilidad
- `FAIL`: hay contradicción contractual visible
- en este primer corte, el exit code del wrapper sigue atado al contrato mínimo del doctor base, no al bloque capability

### `inspect`

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

Ejemplo compacto:

```text
[Progress] el=00:03:41 done=23/267 p/f/s/to=22/1/0/0 cur=test/back/.../FooTest.php cur_el=00:00:37 avg=9.6s/test eta=00:39:12 jobs=3 workers=w1:...@00:00:37, w2:...@00:00:11, w3:...@00:00:04
[Test] status=PASS worker=2 done=24/267 dur=00:00:09 rel=test/back/.../BarTest.php el=00:03:50 p/f/s/to=23/1/0/0 jobs=3 active=w1:...@00:00:46, w3:...@00:00:13
[WARN] long_running_test elapsed=00:01:00 rel=test/back/.../FooTest.php worker=1
[Phase Timings]
  discovery_ms=118
  admission_ms=41
  execution_ms=602311
  reporting_ms=352
```

Lectura correcta:

- `[Progress]` es una señal humana de que la suite sigue viva; no es un formato para automatización
- `workers=` resume workers activos cuando `TEST_JOBS > 1`; sirve para ver quién está ocupado y hace cuánto
- `[Test]` aparece solo en `per_test` y emite una línea por test completado
- `rel`, `cur`, `workers` y `active` pueden aparecer compactados o truncados con elipsis en terminales angostas; el contrato estable es el significado del campo, no el path raw completo
- `avg` y `eta` usan promedio simple de la corrida actual; no hay heurística histórica en esta capa
- `[WARN] long_running_test` es informativo; no cambia exit code ni estado final
- `[WARN] long_running_test` aplica tanto en `heartbeat` como en `per_test`; `quiet` lo suprime
- `[Phase Timings]` resume tiempos gruesos de `discovery`, `admission`, `execution` y `reporting`

Configuración mínima:

- `TESTKIT_PROGRESS_MODE=heartbeat|per_test|quiet`
- `TESTKIT_PROGRESS_INTERVAL_SEC` (default `15`)
- `TESTKIT_LONG_TEST_WARN_SEC` (default `60`)

Lectura de modos:

- `heartbeat`: habilita heartbeats periódicos, warnings de test largo y visibilidad compacta de workers activos
- `per_test`: emite una línea por test completado, mantiene warnings de test largo y muestra workers activos cuando aplica
- `quiet`: suprime `[Progress]`, `[Test]` y warnings de test largo, pero no elimina el reporte final ni `phase_timings_ms`

## 7) Qué comportamiento sí es esperado

| Situación | Lectura correcta |
|---|---|
| `suite_status=no_tests` | la selección quedó vacía; no implica bug por sí sola |
| `suite_status=all_skipped` | la selección entró, pero los tests decidieron skip en runtime |
| contention / locks | ya existe otra corrida top-level sobre el mismo store base |
| `TEST_DB_STRATEGY=clean` rechazado | comportamiento esperado; no es un modo soportado |
| `migration-contract` rechazado fuera de snapshot/shared/MySQL | comportamiento esperado; es una suite técnica acotada |
| `Capability doctor: UNKNOWN` | doctor no tiene evidencia visible suficiente para cerrar esa compatibilidad |

Para causas y pasos concretos, leer [`TROUBLESHOOTING.md`](TROUBLESHOOTING.md).

## 8) Cosas que no conviene sobreinterpretar

- `TEST_LIST=1` imprime selección, pero no debe venderse como dry-run puro del lifecycle de store; según la suite, el bootstrap puede ocurrir igual.
- `doctor` no prueba restore de snapshot ni migraciones.
- `doctor migration-contract` solo evalúa contradicciones visibles por config; no ejecuta restore ni seed-state.
- `scripts/report.php` no es el contrato estable para automatización.
- `per_worker` no habilita varios runners top-level en paralelo.
- `clone-per-worker` no aísla filesystem, colas, APIs ni side effects externos.
- `[Progress]` y `[Test]` no reemplazan `inspect`, el JSON persistido ni el reporte final.
- esta capa no persiste heartbeats individuales ni telemetría por evento.

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


### Capability doctor

`doctor` ahora expone una segunda capa de lectura humana y estructurada:

- `PASS`: ruta visible alineada con el contrato cerrado actual
- `WARN`: hay una lectura visible rara o degradada, pero no necesariamente una contradicción contractual directa
- `UNKNOWN`: el wrapper no tiene evidencia suficiente para afirmar compatibilidad
- `FAIL`: la combinación visible contradice el contrato actual

Reglas duras:

- `UNKNOWN` no equivale a `PASS`
- `WARN` no convierte una ruta no soportada en soportada
- capability no cambia el exit code del wrapper; el exit sigue atado al doctor base

En `doctor --dump`, además de `TESTKIT_CAPABILITY_STATUS`, quedan serializados los checks individuales:

- `TESTKIT_CAPABILITY_CHECK_COUNT`
- `TESTKIT_CAPABILITY_CHECK_<n>_STATUS`
- `TESTKIT_CAPABILITY_CHECK_<n>_CODE`
- `TESTKIT_CAPABILITY_CHECK_<n>_SUMMARY`
- `TESTKIT_CAPABILITY_CHECK_<n>_ACTION`
