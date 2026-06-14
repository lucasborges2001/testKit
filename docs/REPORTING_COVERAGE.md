# Reporting y Coverage

## 1) Qué responde este documento

Usar este documento para responder:

- qué artefactos de reporte escribe realmente `testkit`
- qué campos JSON son canónicos para automatización
- qué significan `suite_status`, `outcome_status` y `no_tests_reason`
- cómo se expone la observabilidad de ejecución
- qué partes del output son para humanos
- qué partes son diagnósticos heurísticos
- cómo leer coverage y qué límites tiene

No usarlo para quick start, troubleshooting de bootstrap/store ni arquitectura interna. Para eso, leer `USO.md`, `TROUBLESHOOTING.md`, `ARQUITECTURA.md` y `CONTRATO.md`.

## 2) Superficies de salida

| Superficie | Para qué sirve | Cómo leerla |
|---|---|---|
| JSON suite/meta bajo `.testkit/reports/` | automatización y evidencia persistida | superficie principal |
| `canonical_report` dentro del JSON | normalización derivada del reporte persistido | útil, pero no sustituye el top-level |
| consola del runner | lectura rápida humana | no consumir como contrato de automatización |
| `php scripts/report.php` | resumen humano agregado | no consumir como contrato estable |
| `inspect` | diagnóstico asistido sobre reportes existentes | interfaz operativa |
| artifacts de coverage | diagnóstico de cobertura | útiles para lectura y tooling, no gate implícito |

## 3) Artefactos persistidos

Por suite:

- `.testkit/reports/<suite>_latest.json`
- `.testkit/reports/<suite>_<timestamp>.json`

Por corrida meta:

- `.testkit/reports/meta_latest.json`
- `.testkit/reports/meta__<target>__<scope>_latest.json` cuando aplica scope/run específico

Índices y auxiliares:

- `.testkit/reports/runs_latest.json`
- `.testkit/reports/latest_run.json` cuando la corrida usó root por run
- `.testkit/history/<suite>.json`

Coverage:

- `.testkit/coverage/<suite_id>/coverage.json`
- `.testkit/coverage/<suite_id>/lcov.info`
- `.testkit/coverage/<suite_id>/coverage_diagnostics.json`
- `.testkit/coverage/<suite_id>/coverage_report.md`

Durante una transición, `scripts/report.php` también lee legacy coverage bajo:

- `test/coverage/php_back`
- `test/coverage/php_front`
- `test/coverage/python_back`

## 4) Qué consumir para automatización

El contrato primario vive en el JSON top-level versionado por:

- `report_contract_version`
- `runner_contract_version`

Campos canónicos a priorizar:

- `suite_status`
- `outcome_status`
- `no_tests_reason`
- `runner_capabilities`
- `summary`
- `failures`
- `first_failure`
- `evidence_valid`
- `evidence_invalid_reason`
- `phase_timings_ms`
- `progress_policy`
- `execution_metrics`

`failures` es la colección canónica de fallos. `failed_tests` existe como fallback legacy y no debe ser la primera elección.

## 5) Estados: no mezclar niveles

### 5.1) `suite_status`

`suite_status` describe el resultado de la selección/ejecución de la suite en un nivel operativo directo.

Valores relevantes:

- `passed`
- `failed`
- `all_skipped`
- `no_tests`
- `listed`

### 5.2) `outcome_status`

`outcome_status` es el estado final enriquecido por diagnóstico.

Valores que el core produce hoy:

- `passed`
- `failed`
- `partial`
- `skipped`
- `no_tests`
- `listed`
- `timeout`
- `contention`
- `infra_error`
- `bootstrap_error`
- `discovery_error`
- `reporting_error`

Para branching de automatización, este es el campo más fuerte.

### 5.3) `no_tests_reason`

`no_tests_reason` solo debe leerse cuando `suite_status=no_tests`.

## 6) Observabilidad

`[Progress]`, `[Test]` y `[Phase Timings]` son señales operatorias humanas. El contrato fuerte para automatización está en el JSON final.

`execution_metrics` resume:

- `selected_test_count`
- `completed_test_count`
- `avg_test_ms`
- `estimated_total_ms`

No hay persistencia de cada heartbeat.

## 7) Humanos vs automatización

Para humanos:

- consola del runner
- `[Progress]`
- `[Test]`
- `[Phase Timings]`
- `php scripts/report.php`
- `inspect`
- `coverage_report.md`

Para automatización:

- JSON suite/meta persistidos
- `failures`
- `summary`
- `suite_status`
- `outcome_status`
- `phase_timings_ms`
- `progress_policy`
- `execution_metrics`
- `canonical_report` solo como ayuda de normalización, no como único origen

## 8) Fragility hints

`fragility_hints` salen del historial local por suite.

Lectura correcta:

- marcan alternancia histórica entre `pass` y `fail`
- dependen del historial disponible en `.testkit/history`
- no prueban causalidad
- no reemplazan triage manual

## 9) Coverage: lectura correcta

Coverage en `testkit` es una señal diagnóstica. Sirve para:

- ver cobertura agregada
- detectar archivos con cobertura baja
- resaltar archivos críticos sin cobertura o bajo threshold

No implica por sí sola un gate contractual de calidad. Si un proyecto quiere usar coverage como gate, ese gate tiene que vivir en la política del proyecto.

## 10) Rutas de coverage

Default canónico:

```text
.testkit/coverage/back_php
.testkit/coverage/front_php
.testkit/coverage/back_python
```

Override canónico:

```bash
TEST_COVERAGE_ROOT=/tmp/cov
```

produce:

```text
/tmp/cov/back_php
```

Compatibilidad legacy:

```bash
TEST_COVERAGE_DIR=/tmp/cov
```

mantiene la semántica histórica de root y también produce:

```text
/tmp/cov/back_php
```

`TEST_COVERAGE_DIR` no debe interpretarse como ruta final nueva. Se conserva para no romper proyectos existentes, pero la configuración nueva debe usar `TEST_COVERAGE_ROOT`.

## 11) Variables de coverage

| Env | Default | Semántica |
|---|---:|---|
| `TEST_COVERAGE` | `0` | Habilita coverage en suites soportadas. |
| `TEST_COVERAGE_FORMAT` | `lcov` | `lcov`, `json` o `both`. |
| `TEST_COVERAGE_ROOT` | `.testkit/coverage` | Root canónico; el runner agrega `<suite_id>`. |
| `TEST_COVERAGE_DIR` | vacío | Alias legacy de root; el runner agrega `<suite_id>`. |
| `TEST_COVERAGE_SOURCE_DIRS` | `TK_BACK_DIR,TK_PUBLIC_DIR` | Directorios fuente incluidos en cálculos. |
| `TEST_COVERAGE_EXCLUDE_DIRS` | `test,testkit,docker,vendor,logs,storage` | Directorios excluidos por política central. |
| `TEST_COVERAGE_CRITICAL_FILES` | vacío | Patrones `fnmatch` repo-relativos para críticos. |
| `TEST_COVERAGE_CRITICAL_THRESHOLD` | `85` | Threshold para `critical_low`. |
| `TEST_COVERAGE_LOW_THRESHOLD` | `70` | Threshold para `low_files`. |
| `TEST_COVERAGE_SUMMARY_TOP` | `10` | Límite de archivos por lista en `scripts/report.php`. |

## 12) Filtro de coverage

La política central está en `CoverageFilter`.

`TEST_COVERAGE_SOURCE_DIRS=back,public_html` significa que el cálculo se hace solo sobre archivos repo-relativos bajo:

```text
back/
public_html/
```

Ese filtro afecta:

- `overall.percent`
- `files`
- `modules`
- `low_files`
- `critical_missing`
- `critical_low`

No debe incluir `testkit/`, `test/`, `vendor/`, `docker/`, `logs/` ni `storage/` salvo que el proyecto cambie explícitamente `TEST_COVERAGE_EXCLUDE_DIRS`.

## 13) Coverage PHP

Artifacts típicos:

- `coverage.json`
- `lcov.info`
- `coverage_diagnostics.json`
- `coverage_report.md`

`coverage_diagnostics.json` incluye:

- `overall`
- `thresholds`
- `files`
- `modules`
- `low_files`
- `critical_missing`
- `critical_low`
- `source_dirs`
- `exclude_dirs`

## 14) Coverage Python

Python usa `trace` de la stdlib.

Lectura correcta:

- es una señal liviana
- no equivale al pipeline diagnóstico PHP
- no debe venderse como analítica avanzada
- puede servir para smoke diagnóstico

## 15) Resumen ejecutivo

`scripts/report.php` lee primero la ruta canónica y luego legacy. El bloque de coverage muestra conteos y listas accionables:

```text
Coverage diagnostics
- back_php: overall=54.54% critical_missing=4 critical_low=10
  missing:
    * back/curso/service/delivery_service.php
    * back/curso/service/contenido_service.php
  low:
    * 2.94% back/curso/service/delivery/pdf_resolver_para_consumo.php
    * 4.35% back/auth/service/plan.php
```

Si hay más de `TEST_COVERAGE_SUMMARY_TOP`, imprime `... N more`.

## 16) Regla práctica de consumo

Para una decisión automática:

1. usar el JSON persistido
2. leer `outcome_status`
3. leer `summary`
4. leer `failures`
5. leer `phase_timings_ms`, `progress_policy` y `execution_metrics`
6. usar `canonical_report` solo como ayuda de uniformidad

Para entender rápido qué pasó:

1. `inspect latest`
2. `inspect failure`
3. `php scripts/report.php`
4. revisar `[Phase Timings]` y, durante la corrida, `[Progress]` o `[Test]`
