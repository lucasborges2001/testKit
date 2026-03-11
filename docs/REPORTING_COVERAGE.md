# Reporting y Coverage

## 1) Que reporta TestKit

Por suite:

- cantidad de tests (`pass/fail/skip`)
- resumen por modulo
- fallas agrupadas
- tests lentos
- violaciones de threshold de perf (si aplica)
- hints de fragilidad (flaky)

Archivo por suite:

- `testkit/_out/reports/<suite>_latest.json`

Historial:

- `testkit/_out/history/<suite>.json`

## 2) Reporte consolidado

Ejecutar:

```bash
php scripts/report.php
```

Entrega una lectura de decision:

- que suite esta fallando
- donde estan los tests lentos
- que tests parecen fragiles
- como viene coverage en zonas importantes

## 3) Coverage con lectura util

Activar en PHP:

```bash
TEST_COVERAGE=1 TEST_COVERAGE_FORMAT=both php runTest.php back-php
```

Archivos:

- `coverage.json` (datos fusionados)
- `lcov.info` (integracion externa)
- `coverage_diagnostics.json` (diagnostico estructurado)
- `coverage_report.md` (lectura humana)

## 4) Diagnosticos soportados

- cobertura por archivo
- cobertura por modulo
- archivos bajo threshold global
- archivos criticos sin cobertura
- archivos criticos con cobertura baja

Variables:

- `TEST_COVERAGE_SOURCE_DIRS`
- `TEST_COVERAGE_LOW_THRESHOLD`
- `TEST_COVERAGE_CRITICAL_FILES`
- `TEST_COVERAGE_CRITICAL_THRESHOLD`

## 5) Smoke / perf / stress

- se soportan por tags y filtros de categoria
- se pueden fijar thresholds de tiempo (`TEST_PERF_MAX_MS`)
- se listan tests lentos por threshold (`TEST_SLOW_THRESHOLD_MS`)

Esto permite detectar degradaciones sin convertir la suite en benchmark de marketing.

## 6) Fragilidad

Los hints `flaky` se generan al mezclar historial de `pass/fail` de un mismo test.

No reemplazan triage manual, pero sirven para priorizar deuda de testing:

- dependencias no aisladas
- tiempos inestables
- efectos colaterales entre tests

## 7) Limites conocidos

- coverage de Python usa `trace` (stdlib), suficiente para smoke diagnostico pero no para analitica avanzada.
- los thresholds son globales; si queres precision fina por test, combinarlos con asserts del propio test.
- un test sin tags solo entra por `scope/match` o categoria `all`.
