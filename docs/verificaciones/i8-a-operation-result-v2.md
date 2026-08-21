# Verificación — I8-A operation_result v2

## Estado

`PENDIENTE_EJECUCION_CHECKOUT_COMPLETO`

## Baseline

```text
testKit/main
8fb3de7dce24ad46a5c88170e25e6aecfa36fd9f
```

Antes de I8-A, la evidencia local aportada para ese baseline fue:

```text
I6 focales: PASS
framework: 51 PASS / 4 FAIL
working tree: clean
```

Fallos observados y clasificados como baseline/no relacionados por alcance de archivos:

```text
Engine support contract
Store driver explicit contract
Core domain boundary
CI typed selector contract
```

I8-A no modifica los archivos que originaron esos cuatro diagnósticos y no los declara resueltos.

## Gates focales

Desde el root de `testKit`:

```bash
php -l core/php/execution/ExitCode.php
php -l core/php/execution/SuiteExecutor.php
php -l core/php/execution/suite/SuiteExecutionResult.php
php -l core/php/execution/suite/SuiteEntryFactory.php
php -l core/php/reporting/OperationResult.php
php -l core/php/reporting/ReportDecorator.php
php -l tests/framework/test_exit_code_v2_contract.php
php -l tests/framework/test_operation_result_v2_contract.php

php tests/framework/test_exit_code_v2_contract.php
php tests/framework/test_operation_result_v2_contract.php
php tests/framework/test_failure_classification_contracts.php
php tests/framework/test_reporting_contract.php
```

## PASS I8-A

- existe una tabla cerrada `0..8` con nombres estables;
- `ExitCode::name()` rechaza códigos desconocidos;
- el motor común usa `6=NO_TESTS` para selección vacía tolerada;
- timeout del motor común usa `8=TIMEOUT`;
- un child test con exit `2` conserva `test.status=skip` sin convertir el proceso de suite en exit `2`;
- all-skipped produce process exit `0` y estado estructurado `skipped`;
- `OperationResult` publica `testkit.operation_result@2` en root solo para resultados marcados como migrados;
- `exit.code` y `exit.name` son coherentes;
- `status` incompatible con `exit` se rechaza;
- schema desconocido/código desconocido se rechaza;
- el marker interno `operation_result_v2_ready` no se persiste.

## FAIL I8-A

- volver a usar process exit `2` como `SKIP` en el motor común;
- convertir un timeout de suite en `TEST_FAILURE=1`;
- publicar un código desconocido dentro del schema v2;
- derivar `exit` desde `suite_status`, `outcome_status`, `final_status` o `canonical_report`;
- declarar migrados engines que todavía mantienen semántica legacy;
- declarar I8 completo solo porque este corte pasa.

## Gate framework

```bash
php tests/framework/run.php
```

Resultado esperado mínimo para considerar que I8-A no agregó regresiones:

```text
los dos tests I8-A nuevos: PASS
los focales afectados: PASS
no aparecen fallos adicionales a los 4 de baseline ya observados
```

No se debe afirmar `framework verde` mientras esos cuatro fallos sigan activos.

## Gates estáticos

```bash
git status --short
git diff --check
find . -type f -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -r -n1 php -l
```

## No verificado todavía

- suite framework sobre el commit I8-A;
- CI remoto;
- PowerShell real;
- comportamiento Node `front-js` all-skipped;
- transporte común en MetaRunner;
- inspect y agent-run;
- eliminación de canonical_report;
- consumers externos;
- Base/Pruebas/gitlinks.
