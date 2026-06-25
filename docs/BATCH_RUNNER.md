# Batch runner seguro

Este documento describe la selección múltiple de tests y el rerun aislado de fallidos.

## Objetivo

Permitir correr varios tests seleccionados dentro de una sola suite para reducir overhead de Docker, healthchecks, bootstrap y seed, sin habilitar concurrencia top-level insegura.

La mejora no reemplaza `ParallelGuard`. La seguridad de DB, locks de store y política `TEST_JOBS` siguen pasando por el guard existente.

## Precedencia de selección

La selección efectiva usa esta precedencia:

1. `TEST_MATCH_FILE`
2. `TEST_MATCH_LIST`
3. `TEST_MATCH`
4. sin filtro explícito

`TEST_MATCH` conserva el comportamiento legacy por substring.

`TEST_MATCH_FILE` y `TEST_MATCH_LIST` usan match exacto por defecto contra el path repo-relative descubierto. Para usar substring debe declararse explícitamente:

```bash
-e TEST_MATCH_LIST_MODE=substring
```

`TEST_SELECTION_MATCH_MODE=substring` se conserva como alias compatible para paquetes previos, pero la configuración nueva debería usar `TEST_MATCH_LIST_MODE`.

## `TEST_MATCH_FILE`

Archivo dentro del repo host con una ruta de test por línea:

```bash
mkdir -p .testkit
cat > .testkit/selection.front_php.txt <<'EOF'
test/front/cliente/unit/cliente_api_wiring.test.php
test/front/cliente/integration/cliente_mi_cargador_tarifa_domestica_seeded_integration.test.php
test/front/cliente/unit/cliente_domestic_asset_endpoint_contract.test.php
test/front/cliente/unit/cliente_mi_cargador_modal_contract.test.php
EOF

./testkit/bin/testkit run --rm \
  -e TEST_MATCH_FILE='.testkit/selection.front_php.txt' \
  testkit php runTest.php front-php
```

Reglas:

- ignora líneas vacías;
- ignora líneas que empiezan con `#`;
- normaliza `\` a `/`;
- rechaza path traversal con `..`;
- rechaza entradas absolutas dentro del archivo;
- las entradas deben ser repo-relative;
- si `TEST_MATCH_FILE` apunta a un archivo inexistente o ilegible, la corrida falla de forma explícita;
- las entradas válidas pero no encontradas no fallan por sí solas, pero quedan en `selection_unmatched_entries`.

## `TEST_MATCH_LIST`

Lista por coma:

```bash
./testkit/bin/testkit run --rm \
  -e TEST_MATCH_LIST='test/front/cliente/unit/cliente_api_wiring.test.php,test/front/cliente/unit/cliente_mi_cargador_modal_contract.test.php' \
  testkit php runTest.php front-php
```

PowerShell:

```powershell
$env:TEST_MATCH_LIST = 'test/front/cliente/unit/cliente_api_wiring.test.php,test/front/cliente/unit/cliente_mi_cargador_modal_contract.test.php'
.\testkit\bin\testkit.ps1 run --rm testkit php runTest.php front-php
Remove-Item Env:\TEST_MATCH_LIST
```

## Metadata de selección

La suite adjunta metadata top-level y en `selection_manifest`:

```json
{
  "selection_source": "match_file",
  "selection_match_mode": "exact",
  "selection_entries_count": 3,
  "selection_entries": ["test/example/a.test.php"],
  "selection_unmatched_entries": ["test/example/missing.test.php"],
  "selection_invalid_entries": [],
  "selection_errors": [],
  "selection_file": ".testkit/selection.front_php.txt",
  "selection_file_exists": true,
  "selected_test_files": ["test/example/a.test.php"]
}
```

`selection_unmatched_entries` indica entradas válidas del selector que no coincidieron con ningún `rel` descubierto luego de aplicar patrones/extensiones. No implica por sí solo fallo de suite salvo que el proyecto use `TEST_REQUIRE_TESTS=1` y la selección completa quede vacía.

## Paralelismo intra-suite

Para unitarios paralelizables:

```bash
./testkit/bin/testkit run --rm \
  -e TEST_MATCH_FILE='.testkit/selection.front_php.txt' \
  -e TEST_JOBS=4 \
  testkit php runTest.php front-php
```

Para integración con DB, solo si el contrato del proyecto soporta `per_worker`:

```bash
./testkit/bin/testkit run --rm \
  -e TEST_MATCH_FILE='.testkit/selection.front_php.txt' \
  -e TEST_JOBS=2 \
  -e TEST_DB_STRATEGY=per_worker \
  testkit php runTest.php front-php
```

No lanzar varios runners top-level en paralelo contra el mismo store. `per_worker` solo aísla workers dentro de una suite. No convierte múltiples `runTest.php` top-level en una configuración segura.

## Rerun aislado de fallidos

Activar con:

```bash
-e TEST_RERUN_FAILED_ISOLATED=1
```

Ejemplo recomendado:

```bash
./testkit/bin/testkit run --rm \
  -e TEST_MATCH_FILE='.testkit/selection.front_php.txt' \
  -e TEST_RERUN_FAILED_ISOLATED=1 \
  -e TEST_COVERAGE=0 \
  testkit php runTest.php front-php
```

Comportamiento:

1. Ejecuta la corrida batch normal.
2. Si no hay fallos, no reejecuta nada.
3. Si hay fallos, toma los archivos fallidos del reporte canónico y ejecuta solo esos archivos uno por uno con `TEST_JOBS=1`.
4. El rerun aislado define `TEST_ISOLATED_RERUN_ACTIVE=1` para impedir recursión.
5. Adjunta el resultado en `isolated_rerun`.

### Exit code

`isolated_rerun` es diagnóstico. No oculta fallos.

```json
{
  "isolated_rerun": {
    "affects_exit_code": false
  }
}
```

Si el batch falla y el rerun aislado pasa, el exit code sigue siendo `1` por defecto. Eso evita convertir interferencia, orden inestable o flakiness en un falso éxito.

### Coverage

Durante el rerun aislado, coverage se desactiva aunque la corrida batch tenga `TEST_COVERAGE=1`.

```json
{
  "isolated_rerun": {
    "coverage_policy": "disabled_for_isolated_rerun"
  }
}
```

La decisión evita mezclar artefactos de coverage de la corrida principal con los procesos diagnósticos secundarios. Si en una fase futura se necesita coverage del rerun aislado, debe escribirse en un directorio separado.

### Interpretación

| Batch | Aislado | Diagnosis |
|---|---|---|
| `fail` | `fail` | `confirmed_failure` |
| `fail` | `pass` | `interference_suspected` |
| `timeout` | `pass` | `interference_suspected` |
| `timeout` | `timeout` | `confirmed_failure` |
| `fail` | `skip` / `no_tests` | `inconclusive` |

`interference_suspected` significa que el archivo falló dentro del batch pero pasó aislado. Es una señal fuerte de interferencia por estado compartido, orden, seed, archivos, memoria global, clock, recursos externos o acoplamiento entre tests. No prueba causalidad por sí solo.

`confirmed_failure` significa que el fallo se reproduce aislado y debe tratarse como fallo real del archivo o de su entorno mínimo.

## JSON mínimo de rerun aislado

```json
{
  "isolated_rerun": {
    "enabled": true,
    "attempted": true,
    "active_guard": false,
    "affects_exit_code": false,
    "coverage_policy": "disabled_for_isolated_rerun",
    "failed_files_count": 1,
    "results": [
      {
        "file": "test/example.test.php",
        "batch_status": "fail",
        "isolated_status": "pass",
        "diagnosis": "interference_suspected",
        "duration_ms": 123
      }
    ],
    "summary": {
      "confirmed_failures": 0,
      "interference_suspected": 1,
      "inconclusive": 0
    }
  }
}
```

## Tags de aislamiento

Tags reconocidos:

| Tag | Significado | Política |
|---|---|---|
| `memory-isolated` | Sensible a estado estático/global del proceso | PHP ya ejecuta cada archivo en proceso separado; queda como metadata. |
| `db-isolated` | Requiere DB limpia o worker propio | Se trata como DB-sensitive para `ParallelGuard`. |
| `serial` | No paralelizar | Rechaza `TEST_JOBS>1`. |
| `fragile` | Posible flaky/intermitente | Útil para priorizar rerun aislado y triage; no cambia exit code. |
