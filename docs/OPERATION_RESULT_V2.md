# Operation result v2 — I8-A

## Propósito

I8 define una sola semántica de resultado de proceso para TestKit. El primer corte, **I8-A**, introduce el contrato raíz versionado y migra el motor común `SuiteExecutor` sin fingir que todas las superficies ya están normalizadas.

Schema raíz:

```json
{
  "schema": {
    "name": "testkit.operation_result",
    "version": 2
  },
  "operation": "run_suite",
  "exit": {
    "code": 0,
    "name": "OK"
  },
  "status": "passed",
  "evidence_valid": true,
  "evidence_invalid_reason": null
}
```

## Tabla cerrada de exit codes

| code | name | significado |
| ---: | --- | --- |
| 0 | `OK` | operación completada contractualmente |
| 1 | `TEST_FAILURE` | ejecución válida con tests/checks fallidos |
| 2 | `INVALID_REQUEST` | request, flags, selector, config o schema inválidos |
| 3 | `OPERATIONAL_ERROR` | fallo de infraestructura, IO, bootstrap, reporting o dependencia |
| 4 | `EVIDENCE_INCOMPLETE` | evidencia insuficiente, incompatible o no verificable |
| 5 | `POLICY_BLOCKED` | policy/gate válido bloqueó la operación |
| 6 | `NO_TESTS` | selección válida sin tests ejecutables |
| 7 | `CONTENTION` | ownership/lock/recurso concurrente rechazó la operación |
| 8 | `TIMEOUT` | timeout contractual |

`ExitCode` es la autoridad de nombres y códigos. Un código desconocido no puede publicarse como `operation_result` válido.

## Separación process vs child

El valor `2` deja de significar `SKIP` en el **proceso TestKit**.

Todavía existe un bridge interno para child tests:

```text
child exit 2 -> test.status=skip
```

Ese bridge no cambia el exit code del proceso de suite. Si todos los tests seleccionados terminan en `skip`, el proceso de suite devuelve `0` y conserva `suite_status=skipped`.

## Semántica migrada en I8-A

Para suites ejecutadas por el motor común PHP:

```text
fallo normal       -> 1 TEST_FAILURE
no tests tolerado  -> 6 NO_TESTS
timeout             -> 8 TIMEOUT
all child skipped   -> 0 OK + status=skipped
```

`TEST_REQUIRE_TESTS=1` conserva temporalmente el bridge de policy existente que promueve una selección vacía a `TEST_FAILURE`. Eliminar esa ambigüedad pertenece al siguiente corte de I8 y debe coordinarse con los consumidores.

## Publicación gradual

`SuiteExecutionResult` marca internamente los resultados listos para v2. `ReportDecorator` consume esa marca, la elimina y publica el schema raíz mediante `OperationResult`.

La marca interna nunca debe persistirse en JSON.

Esto evita declarar v2 sobre engines que todavía no respetan la tabla completa.

## Compatibilidad temporal

Durante I8-A todavía pueden coexistir campos legacy y el subtree `canonical_report`. Para los reports que ya publican `testkit.operation_result@2`, la autoridad nueva es:

```text
schema + operation + exit + status + evidence_valid
```

Los campos legacy no se consultan para decidir el significado de `exit`.

## Fuera de alcance de I8-A

Todavía no se declara cerrado:

- `front-js` standalone/Node;
- `sql-observability` completo;
- agregación y transporte de código en `MetaRunner`;
- `scripts/inspect.php`;
- `scripts/agent-run.php`;
- eliminación de `canonical_report`;
- eliminación de fallbacks `outcome_status -> suite_status -> final_status`;
- propagación específica `CONTENTION=7` en todos los caminos operacionales;
- policy final para `require_tests`;
- consumidores externos, Base, Pruebas o gitlinks.

## Validación focal

```bash
php tests/framework/test_exit_code_v2_contract.php
php tests/framework/test_operation_result_v2_contract.php
php tests/framework/test_failure_classification_contracts.php
php tests/framework/test_reporting_contract.php
```

Gate amplio:

```bash
php tests/framework/run.php
```
