# Batch runner seguro

Este documento describe selección múltiple de tests y rerun aislado de fallidos sobre el contrato público tipado de TestKit.

## Objetivo

Permitir correr varios tests seleccionados dentro de una sola suite para reducir overhead de Docker, healthchecks, bootstrap y seed, sin habilitar concurrencia top-level insegura.

La mejora no reemplaza `ParallelGuard`. La seguridad de DB, locks de store y política `TEST_JOBS` siguen pasando por el guard existente.

## Contrato público de selección

Toda corrida declara exactamente uno de:

```text
--suite
--group
--category
```

La selección explícita de archivos usa únicamente:

```text
--test <repo-relative>             # repetible
--selection-file <repo-relative>   # lote declarado
```

`--test` y `--selection-file` son mutuamente excluyentes. Las rutas absolutas y traversal con `..` se rechazan.

Ejemplos:

```bash
php runTest.php --suite front-php \
  --test test/front/cliente/unit/cliente_api_wiring.test.php \
  --test test/front/cliente/unit/cliente_mi_cargador_modal_contract.test.php
```

```bash
mkdir -p .testkit
cat > .testkit/selection.front_php.txt <<'EOF'
test/front/cliente/unit/cliente_api_wiring.test.php
test/front/cliente/integration/cliente_mi_cargador_tarifa_domestica_seeded_integration.test.php
test/front/cliente/unit/cliente_domestic_asset_endpoint_contract.test.php
test/front/cliente/unit/cliente_mi_cargador_modal_contract.test.php
EOF

php runTest.php --suite front-php \
  --selection-file .testkit/selection.front_php.txt
```

Desde el wrapper Docker:

```bash
./testkit/bin/testkit run --rm testkit php runTest.php \
  --suite front-php \
  --selection-file .testkit/selection.front_php.txt
```

PowerShell:

```powershell
.\testkit\bin\testkit.ps1 run --rm testkit php runTest.php `
  --suite front-php `
  --selection-file .testkit/selection.front_php.txt
```

## Bridge interno legacy

El runtime todavía puede traducir temporalmente `--test` y `--selection-file` a variables internas `TEST_MATCH_LIST`, `TEST_MATCH_FILE` y `TEST_MATCH_LIST_MODE`.

Eso es deuda de implementación de I4, no contrato público para consumidores.

No documentar ni introducir nueva configuración basada en:

```text
TEST_MATCH
TEST_MATCH_LIST
TEST_MATCH_FILE
TEST_MATCH_LIST_MODE
TEST_SELECTION_MATCH_MODE
```

El criterio de cierre de I4 está en `docs/pendientes/normalizacion-contratos/pendiente-interno-testkit.md`.

## Archivo de selección

`--selection-file` apunta a un archivo dentro del repo host con una ruta de test por línea.

Reglas esperadas:

- ignora líneas vacías;
- puede ignorar comentarios `#` según el parser interno vigente;
- normaliza separadores de path cuando corresponde;
- rechaza path traversal con `..`;
- rechaza rutas absolutas;
- las entradas deben ser repo-relative;
- archivo inexistente o ilegible debe fallar explícitamente.

## Metadata de selección

La suite puede adjuntar metadata top-level y en `selection_manifest`:

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

Los nombres `match_file`/`selection_match_mode` describen metadata interna persistida. No convierten las variables `TEST_MATCH*` en API pública.

`selection_unmatched_entries` indica entradas válidas que no coincidieron con ningún archivo descubierto. No implica por sí solo fallo de suite salvo que el contrato de la corrida exija tests y la selección completa quede vacía.

## Paralelismo intra-suite

Para unitarios paralelizables:

```bash
TEST_JOBS=4 php runTest.php \
  --suite front-php \
  --selection-file .testkit/selection.front_php.txt
```

Para integración con DB, solo si el contrato del proyecto soporta `per_worker`:

```bash
TEST_JOBS=2 \
TEST_DB_STRATEGY=per_worker \
php runTest.php \
  --suite front-php \
  --selection-file .testkit/selection.front_php.txt
```

No lanzar varios runners top-level en paralelo contra el mismo store. `per_worker` solo aísla workers dentro de una suite.

## Rerun aislado de fallidos

Activar con:

```bash
TEST_RERUN_FAILED_ISOLATED=1
```

Ejemplo:

```bash
TEST_RERUN_FAILED_ISOLATED=1 \
TEST_COVERAGE=0 \
php runTest.php \
  --suite front-php \
  --selection-file .testkit/selection.front_php.txt
```

Comportamiento:

1. ejecuta la corrida batch normal;
2. si no hay fallos, no reejecuta nada;
3. si hay fallos, toma los archivos fallidos del reporte canónico y los ejecuta uno por uno con `TEST_JOBS=1`;
4. usa `TEST_ISOLATED_RERUN_ACTIVE=1` como guard interno para impedir recursión;
5. adjunta el resultado en `isolated_rerun`.

### Exit code

`isolated_rerun` es diagnóstico y no oculta fallos.

```json
{
  "isolated_rerun": {
    "affects_exit_code": false
  }
}
```

Si el batch falla y el rerun aislado pasa, el exit code del batch continúa siendo fallido.

### Coverage

Durante el rerun aislado, coverage se desactiva para no mezclar artefactos con la corrida principal:

```json
{
  "isolated_rerun": {
    "coverage_policy": "disabled_for_isolated_rerun"
  }
}
```

### Interpretación

| Batch | Aislado | Diagnóstico |
|---|---|---|
| `fail` | `fail` | `confirmed_failure` |
| `fail` | `pass` | `interference_suspected` |
| `timeout` | `pass` | `interference_suspected` |
| `timeout` | `timeout` | `confirmed_failure` |
| `fail` | `skip` / `no_tests` | `inconclusive` |

`interference_suspected` es una señal de posible estado compartido, orden, seed, filesystem, memoria global, reloj o recursos externos. No prueba causalidad por sí sola.

`confirmed_failure` significa que el fallo se reproduce aislado y debe tratarse como fallo real del archivo o de su entorno mínimo.

## Tags de aislamiento

| Tag | Significado | Política |
|---|---|---|
| `memory-isolated` | Sensible a estado estático/global del proceso | Metadata; PHP ejecuta archivos en procesos separados. |
| `db-isolated` | Requiere DB limpia o worker propio | DB-sensitive para `ParallelGuard`. |
| `serial` | No paralelizar | Rechaza `TEST_JOBS>1`. |
| `fragile` | Posible flaky/intermitente | Prioriza triage; no cambia exit code. |

## Regla de consumo

Para documentación nueva, scripts de hosts y agentes:

```text
selector tipado
+ --test / --selection-file
+ JSON persistido
```

No usar variables legacy de selección como superficie de integración.