# testKit cleanup

`cleanup` elimina artefactos operativos generados por testKit en el repo host.

El objetivo es reducir crecimiento de `.testkit/` sin borrar tests, seeds, bases de datos ni volúmenes de Docker.

## Uso rápido

```bash
./bin/testkit cleanup reports --dry-run
./bin/testkit cleanup reports --apply
```

En PowerShell:

```powershell
.\bin\testkit.ps1 cleanup reports --dry-run
.\bin\testkit.ps1 cleanup reports --apply
```

`--dry-run` es el modo por defecto. Para borrar realmente, usar `--apply`.

## Comandos

```bash
./bin/testkit cleanup all --dry-run
./bin/testkit cleanup reports --keep-runs=10 --keep-days=14 --apply
./bin/testkit cleanup profiles --keep-runs=10 --keep-days=14 --apply
./bin/testkit cleanup coverage --apply
./bin/testkit cleanup locks --apply
./bin/testkit cleanup history --apply
./bin/testkit cleanup baselines --force --apply
```

## Grupos

| Grupo | Qué revisa | Default seguro |
| --- | --- | --- |
| `reports` | `.testkit/reports/runs/<run_id>` y JSON timestamped viejos | sí |
| `profiles` | `.testkit/mysql_profile/shards/<run_id>` y `.testkit/influx_profile/shards/<run_id>` | sí |
| `coverage` | `test/coverage` y `TEST_COVERAGE_DIR` seguro | sí |
| `locks` | `.testkit/locks/*` | solo locks stale |
| `history` | `.testkit/history/*.json` | solo si se pide explícitamente, o con `--include-history` en `all` |
| `baselines` | `.testkit/baselines/**/*.manifest.json` | requiere `--force` |

## Retención

`cleanup` conserva artefactos por dos criterios:

- `--keep-runs=N`: conserva los N más recientes.
- `--keep-days=N`: conserva artefactos más nuevos que N días.

Un artefacto se borra solo si queda fuera de ambos criterios.

Default:

```bash
--keep-runs=10 --keep-days=14
```

## JSON

```bash
./bin/testkit cleanup reports --dry-run --json
```

Devuelve un payload con:

- `summary.scanned`
- `summary.delete_candidates`
- `summary.bytes_reclaimable`
- `groups.*`
- `candidates[]`
- `errors[]`

## Auditoría

Cada ejecución escribe:

```text
.testkit/reports/cleanup/cleanup_latest.json
.testkit/reports/cleanup/cleanup_<timestamp>.json
```

Esto ocurre también en dry-run, para dejar evidencia del plan calculado.

## Seguridad

`cleanup` no borra:

- bases de datos
- volúmenes Docker
- seeds
- tests fuente
- `test/_support`
- `.env.test`
- `*_latest.json`
- `latest_run.json`
- locks activos, salvo `--all-locks --force`

La limpieza de baselines solo borra manifests `.manifest.json`. No intenta dropear databases ni resetear stores.

## Casos recomendados

### Reducir explosión de corridas

```bash
./bin/testkit cleanup reports --keep-runs=10 --keep-days=7 --dry-run
./bin/testkit cleanup reports --keep-runs=10 --keep-days=7 --apply
```

### Limpiar profiling viejo

```bash
./bin/testkit cleanup profiles --keep-runs=5 --keep-days=7 --apply
```

### Limpiar cobertura generada

```bash
./bin/testkit cleanup coverage --apply
```

### Limpiar locks stale

```bash
./bin/testkit cleanup locks --apply
```

