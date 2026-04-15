# Reporting y Coverage

## 1) Qué responde este documento

Usar este documento para responder:

- qué artefactos de reporte escribe realmente `testkit`
- qué campos JSON son canónicos para automatización
- qué significan `suite_status`, `outcome_status` y `no_tests_reason`
- qué partes del output son para humanos
- qué partes son diagnósticos heurísticos
- cómo leer coverage y qué límites tiene

No usarlo para:

- quick start
- troubleshooting de bootstrap/store
- arquitectura interna del lifecycle
- redefinir el contrato general de adopción

Para eso, leer:

- [`USO.md`](USO.md)
- [`TROUBLESHOOTING.md`](TROUBLESHOOTING.md)
- [`ARQUITECTURA.md`](ARQUITECTURA.md)
- [`CONTRATO.md`](CONTRATO.md)

## 2) Superficies de salida

`testkit` emite varias superficies. No tienen el mismo peso contractual.

| Superficie | Para qué sirve | Cómo leerla |
|---|---|---|
| JSON suite/meta bajo `.testkit/reports/` | automatización y evidencia persistida | superficie principal |
| `canonical_report` dentro del JSON | normalización derivada del reporte persistido | útil, pero no sustituye el top-level |
| consola del runner | lectura rápida humana | no consumir como contrato estable |
| `php scripts/report.php` | resumen humano agregado | no consumir como contrato estable |
| `inspect` | diagnóstico asistido sobre reportes existentes | interfaz operativa, no formato persistido |
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

Coverage PHP:

- `coverage.json`
- `lcov.info`
- `coverage_diagnostics.json`
- `coverage_report.md`

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

`failures` es la colección canónica de fallos.
`failed_tests` existe como fallback legacy y no debe ser la primera elección.

## 5) Estados: no mezclar niveles

### 5.1) `suite_status`

` suite_status` describe el resultado de la selección/ejecución de la suite en un nivel operativo directo.

Valores relevantes que hoy aparecen:

- `passed`
- `failed`
- `all_skipped`
- `no_tests`
- `listed`

Lectura correcta:

- `no_tests` significa que la selección quedó vacía
- `all_skipped` significa que entraron tests, pero todos se saltaron
- `listed` significa modo list-only

No usar `suite_status` como único “resultado final” de la corrida.

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

Hoy suele expresar que ningún test coincidió con los filtros activos (`scope`, `category`, `match`).

No leerlo como explicación general de cualquier fallo.

## 6) Campos principales

### `runner_capabilities`

Declara capacidades del runner/suite.
Es metadato contractual del runner, no evidencia de que una corrida concreta haya usado todas esas capacidades.

### `summary`

Es un agregado compacto del resultado ya enriquecido.

Usarlo para:

- `total`
- `passed`
- `failed`
- `skipped`
- `duration_ms`

También puede incluir campos derivados como:

- `suite_status`
- `outcome_status`
- `status_counts`
- `phase_failure_counts`
- `cause_counts`

No inferir semántica fuera de lo que el propio reporte ya resolvió.

### `failures`

Es la lista canónica de fallos normalizados.

Cada entrada puede incluir, entre otros:

- `test_id`
- `suite_id`
- `file`
- `case`
- `kind`
- `phase`
- `failure_domain`
- `cause_code`
- `message`
- `exception_class`
- `artifact_path`

Consumirla como evidencia principal de fallo.

## 7) Qué es estable y qué es derivado

### 7.1) Más estable

- campos top-level versionados
- `suite_status`
- `outcome_status`
- `summary`
- `failures`
- `first_failure`
- `report_links`
- `selection_manifest`

### 7.2) Derivado pero útil

- `canonical_report`
- `diagnostics`
- `phase_timeline`
- `normalized_artifacts`
- `regression_delta`

### 7.3) Heurístico o de ayuda

- `fragility_hints`
- familias/clusters de fallo
- `recommended_actions`
- `agent_summary`
- lectura humana de `scripts/report.php`

Estos campos ayudan a triage. No deben tratarse como verdad fuerte del dominio.

## 8) Humanos vs automatización

### Para humanos

- consola del runner
- `php scripts/report.php`
- `inspect`
- `coverage_report.md`

### Para automatización

- JSON suite/meta persistidos
- `failures`
- `summary`
- `suite_status`
- `outcome_status`
- `canonical_report` solo como ayuda de normalización, no como único origen

## 9) Fragility hints

`fragility_hints` hoy salen del historial local por suite.

Lectura correcta:

- marcan alternancia histórica entre `pass` y `fail`
- dependen del historial disponible en `.testkit/history`
- no prueban causalidad
- no reemplazan triage manual

Sirven para priorizar investigación, no para cerrar diagnóstico.

## 10) Coverage: lectura correcta

## 10.1) Coverage como diagnóstico

Coverage en `testkit` es, ante todo, una señal diagnóstica.

Sirve para:

- ver cobertura agregada
- detectar archivos con cobertura baja
- resaltar archivos críticos sin cobertura o bajo threshold

No implica por sí sola un gate contractual de calidad.

Si un proyecto quiere usar coverage como gate, ese gate tiene que vivir en la política del proyecto, no asumirse implícitamente desde `testkit`.

## 10.2) Coverage PHP

La ruta diagnóstica cerrada hoy está en suites PHP.

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

Es diagnóstico estructurado. No describe exhaustivamente la intención del proyecto.

## 10.3) Coverage Python

Python usa `trace` de la stdlib.

Lectura correcta:

- es una señal liviana
- no equivale al pipeline diagnóstico PHP
- no debe venderse como analítica avanzada
- puede servir para smoke diagnóstico, no para conclusiones finas por sí sola

## 11) Regla práctica de consumo

Si necesitás una decisión automática:

1. usar el JSON persistido
2. leer `outcome_status`
3. leer `summary`
4. leer `failures`
5. usar `canonical_report` solo como ayuda de uniformidad

Si necesitás entender rápido qué pasó:

1. `inspect latest`
2. `inspect failure`
3. `php scripts/report.php`

No mezclar ambas capas como si fueran el mismo contrato.
