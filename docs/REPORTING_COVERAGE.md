# Reporting y Coverage

## 1) Qué responde este documento

Usar este documento para responder:

- qué artefactos de reporte escribe TestKit;
- qué campos JSON son canónicos para automatización;
- qué significan `suite_status`, `outcome_status` y `no_tests_reason`;
- cómo se expone la observabilidad de ejecución;
- qué partes del output son para humanos;
- cómo leer coverage y cuáles son sus límites.

Para quick start, troubleshooting, arquitectura y adopción leer respectivamente `USO.md`, `TROUBLESHOOTING.md`, `ARQUITECTURA.md` y `CONTRATO.md`.

## 2) Superficies de salida

| Superficie | Para qué sirve | Contrato |
|---|---|---|
| JSON suite/meta bajo `.testkit/reports/` | automatización y evidencia persistida | principal |
| `canonical_report` dentro del JSON | normalización derivada | auxiliar |
| consola del runner | lectura humana | no contractual |
| `php scripts/report.php` | resumen humano agregado | no contractual |
| `inspect` | diagnóstico sobre reportes persistidos | operativo |
| artifacts de coverage | diagnóstico de cobertura | no implican gate por sí solos |

La consola no debe parsearse como interfaz de máquina.

## 3) Artefactos persistidos

Los reportes viven bajo `.testkit/reports/` y el historial bajo `.testkit/history/`.

Coverage canónico por suite:

```text
.testkit/coverage/<suite_id>/coverage.json
.testkit/coverage/<suite_id>/lcov.info
.testkit/coverage/<suite_id>/coverage_diagnostics.json
.testkit/coverage/<suite_id>/coverage_report.md
.testkit/coverage/<suite_id>/coverage_meta.json
```

El runtime todavía puede reconocer rutas históricas bajo `test/coverage/`. Esa compatibilidad es deuda de I5 y no debe usarse para nuevas integraciones ni presentarse como ruta pública alternativa.

## 4) Qué consumir para automatización

El contrato primario vive en JSON versionado. Priorizar:

- `suite_status`;
- `outcome_status`;
- `no_tests_reason`;
- `runner_capabilities`;
- `summary`;
- `failures`;
- `first_failure`;
- `evidence_valid`;
- `evidence_invalid_reason`;
- `phase_timings_ms`;
- `progress_policy`;
- `execution_metrics`.

`failures` es la colección canónica. Campos legacy sólo deben leerse para compatibilidad de reportes históricos, no como contrato nuevo.

## 5) Estados

### `suite_status`

Describe el resultado directo de selección/ejecución de la suite. Valores observados incluyen:

```text
passed
failed
all_skipped
no_tests
listed
```

### `outcome_status`

Es el estado final enriquecido por diagnóstico. Valores observados incluyen:

```text
passed
failed
partial
skipped
no_tests
listed
timeout
contention
infra_error
bootstrap_error
discovery_error
reporting_error
```

Para branching de automatización, `outcome_status` es el campo de mayor nivel.

### `no_tests_reason`

Leerlo cuando `suite_status=no_tests`.

## 6) Observabilidad

`[Progress]`, `[Test]` y `[Phase Timings]` son señales humanas. La evidencia contractual permanece en el JSON persistido.

`execution_metrics` puede resumir:

- `selected_test_count`;
- `completed_test_count`;
- `avg_test_ms`;
- `estimated_total_ms`.

No inferir persistencia de cada heartbeat si no está en el reporte.

## 7) Fragility hints

`fragility_hints` es diagnóstico heurístico derivado del historial local. Puede ayudar a priorizar triage, pero:

- no prueba causalidad;
- depende del historial disponible;
- no reemplaza rerun focalizado ni inspección del fallo.

## 8) Coverage

Coverage es una señal diagnóstica. No constituye por sí sola un gate contractual de calidad.

Un proyecto que quiera imponer umbrales obligatorios debe hacerlo mediante una política explícita de ese proyecto.

## 9) Ruta canónica de coverage

Default:

```text
.testkit/coverage/<suite_id>
```

Override público recomendado:

```bash
TEST_COVERAGE_ROOT=/tmp/cov
```

Para `back_php` produce:

```text
/tmp/cov/back_php
```

### Deuda legacy

El runtime actual todavía puede reconocer:

```text
TEST_COVERAGE_DIR
legacy test/coverage/*
```

No usar esas superficies en configuración nueva. Su eliminación pertenece a I5 en:

```text
docs/pendientes/normalizacion-contratos/pendiente-interno-testkit.md
```

Documentarlas aquí sólo registra deuda compatible existente; no las convierte en contrato recomendado.

## 10) Variables de coverage vigentes para nuevos consumidores

| Env | Default | Semántica |
|---|---:|---|
| `TEST_COVERAGE` | `0` | Habilita coverage donde la suite lo soporte. |
| `TEST_COVERAGE_FORMAT` | `lcov` | `lcov`, `json` o `both`. |
| `TEST_COVERAGE_ROOT` | `.testkit/coverage` | Root canónico; agrega `<suite_id>`. |
| `TEST_COVERAGE_SOURCE_DIRS` | según suite/config | Directorios fuente incluidos. |
| `TEST_COVERAGE_EXCLUDE_DIRS` | política del framework | Directorios excluidos. |
| `TEST_COVERAGE_CRITICAL_FILES` | vacío | Patrones repo-relative para archivos críticos. |
| `TEST_COVERAGE_CRITICAL_THRESHOLD` | `85` | Threshold de `critical_low`. |
| `TEST_COVERAGE_LOW_THRESHOLD` | `70` | Threshold de `low_files`. |
| `TEST_COVERAGE_SUMMARY_TOP` | `10` | Límite del resumen humano. |

La autoridad exacta de configuración sigue siendo el runtime/schema vigente; este documento no debe inventar aliases para completar huecos.

## 11) Filtro de coverage

La política central está en `CoverageFilter`.

Ejemplo:

```bash
TEST_COVERAGE_SOURCE_DIRS=back,public_html
```

El filtro puede afectar:

- `overall.percent`;
- `files`;
- `modules`;
- `low_files`;
- `critical_missing`;
- `critical_low`.

Las exclusiones deben mantenerse consistentes con la configuración efectiva del runner.

## 12) Metadata anti-stale

Cuando una suite genera coverage, TestKit escribe `coverage_meta.json` junto a los artefactos. Campos relevantes incluyen:

- `suite_id`;
- `generated_at`;
- `coverage_dir` / `coverage_dir_rel`;
- `report_root` / `report_root_rel`;
- `run_id` / `meta_run_id`;
- `coverage_enabled`;
- `coverage_format`;
- `source_dirs`;
- `exclude_dirs`;
- referencias a archivos de coverage;
- `diagnostics_summary`.

El objetivo es impedir que archivos viejos se presenten como evidencia de la corrida actual.

Ejemplo de attachment generado:

```json
{
  "coverage": {
    "enabled": true,
    "generated": true,
    "status": "generated",
    "dir": "/workspace/project/.testkit/coverage/back_php",
    "run_id": "20260614T204957Z_d21d61",
    "overall_percent": 59.7,
    "critical_missing_count": 0,
    "critical_low_count": 0
  }
}
```

Si coverage no se generó en la corrida, el reporte no debe reutilizar silenciosamente datos anteriores como actuales.

## 13) Coverage PHP y Python

### PHP

Artifacts típicos:

```text
coverage.json
lcov.info
coverage_diagnostics.json
coverage_report.md
```

`coverage_diagnostics.json` puede incluir `overall`, `thresholds`, `files`, `modules`, `low_files`, `critical_missing`, `critical_low`, `source_dirs` y `exclude_dirs`.

### Python

Python usa una señal de coverage más liviana. No debe venderse como equivalente analítico al pipeline PHP si el runtime no lo demuestra.

## 14) Resumen humano

`scripts/report.php` puede mostrar coverage asociado a la última corrida. Ese resumen es para operador; la decisión automática debe volver al JSON persistido.

Una ruta legacy sin metadata compatible debe tratarse como stale/legacy, nunca como evidencia actual.

## 15) Regla práctica de consumo

Para automatización:

```text
JSON persistido
-> outcome_status
-> summary
-> failures
-> evidence_valid
-> métricas/diagnóstico necesario
```

Para lectura humana:

```text
inspect latest
inspect failure
php scripts/report.php
```

## 16) No verificado por este documento

Este documento no declara:

- que I5 esté cerrado;
- que las rutas legacy ya hayan sido eliminadas del runtime;
- que coverage tenga gate obligatorio por defecto;
- que todos los lenguajes tengan la misma profundidad de instrumentación.

El contrato canónico debe converger hacia una única raíz de coverage y sin aliases legacy; hasta entonces la diferencia permanece registrada como deuda explícita.