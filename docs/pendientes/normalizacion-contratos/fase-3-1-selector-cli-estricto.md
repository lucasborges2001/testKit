# Fase 3.1 — Selector CLI estricto

## Estado

Implementado en `testKit`.

Este commit cierra exclusivamente el selector público de corridas. Los aliases de selección, store, stack y coverage permanecen para Fase 3.2 y no forman parte del criterio PASS de este corte.

## Baseline

```text
Repositorio: lucasborges2001/testKit
Rama base: main
Commit base auditado: b5b09284c69728dfa93266300f405e3e57157684
Rama de trabajo: agent/testkit-contract-normalization
Commit anterior: 270fb033ec90e4a04ac45869810110c067501a7c
Fecha: 2026-07-28
```

## Objetivo

Reemplazar el target ambiguo por una solicitud tipada:

```text
--suite <nombre>
--group <nombre>
--category <nombre>
```

Toda corrida debe declarar exactamente un selector.

## Contrato público

Ejemplos válidos:

```bash
php runTest.php --suite back-php
php runTest.php --group all --list
php runTest.php --category smoke
php runTest.php --suite back-php --test test/back/auth/login.test.php
php runTest.php --suite back-php --selection-file test/selection.txt
```

Ejemplos inválidos:

```bash
php runTest.php back-php
php runTest.php --suite back-py
php runTest.php --group public_html
php runTest.php --suite back-php --group all
TEST_TARGET=all php runTest.php --suite back-php
TESTKIT_TARGET_CUSTOM=back_php php runTest.php --suite back-php
```

Las solicitudes inválidas devuelven código `2`.

## Eliminaciones

Se retiraron del contrato ejecutable:

- targets posicionales;
- aliases `back-py`, `python`, `py`, `http`, `migration`, `migrations`, `references` y `php-references`;
- grupo legacy `public_html`;
- extensiones dinámicas `TESTKIT_TARGET_*`;
- selección por `TEST_TARGET`.

No existe fallback hacia el nombre canónico.

## Arquitectura

### Autoridad

```text
ContractRegistry
  ├── suites
  ├── groups
  ├── categories
  └── selectorDefinitions(kind, name)
```

### Parsing

```text
RunRequest
  -> valida gramática
  -> valida tipo y nombre contra ContractRegistry
  -> rechaza aliases y env legacy
  -> publica selector interno normalizado
  -> MetaRunner
  -> TargetResolver::resolveTyped()
```

`TargetResolver` ya no contiene mapas propios ni consulta `TESTKIT_TARGET_*`.

### Doctor

Bash y PowerShell aceptan los mismos selectores tipados:

```bash
./bin/testkit doctor --suite back-php --compact
./bin/testkit doctor --group all --compact
./bin/testkit doctor --category smoke --compact
```

`--target` y los selectores posicionales fallan.

## Selección de archivos

`--test` es repetible y acepta únicamente rutas repo-relative exactas.

`--selection-file` acepta una ruta repo-relative.

Ambas opciones son mutuamente excluyentes.

En este commit existe un puente interno hacia las variables de selección actuales. Ese puente no es contrato público y se elimina en Fase 3.2 junto con:

- `TEST_MATCH`;
- `TEST_MATCH_LIST`;
- `TEST_MATCH_FILE`;
- `TEST_MATCH_LIST_MODE`;
- `TEST_SELECTION_MATCH_MODE`.

## Archivos afectados

```text
M core/php/config/ContractRegistry.php
A core/php/config/RunRequest.php
M core/php/suites/TargetResolver.php
M runners/runTest.php
M scripts/contract.php
M lib/bash/doctor/contract_registry.sh
M lib/powershell/Doctor.ContractRegistry.ps1
M tests/framework/test_contract_registry.php
A tests/framework/test_strict_run_request.php
M tests/framework/run.php
M docs/CONTRACT_REGISTRY.md
A docs/pendientes/normalizacion-contratos/fase-3-1-selector-cli-estricto.md
M AGENTS.md
```

## Invariantes

1. una corrida tiene exactamente un selector;
2. selector kind pertenece a `suite|group|category`;
3. nombre y kind deben coincidir en el registro;
4. no existen aliases;
5. `public_html` no existe como grupo;
6. `TEST_TARGET` y `TESTKIT_TARGET_*` son inválidos;
7. rutas `--test` no son absolutas y no contienen traversal;
8. `--test` y `--selection-file` no se combinan;
9. doctor y runner consultan la misma autoridad;
10. request inválido devuelve `2`.

## Validación

```bash
php -l core/php/config/ContractRegistry.php
php -l core/php/config/RunRequest.php
php -l core/php/suites/TargetResolver.php
php -l runners/runTest.php
php -l scripts/contract.php
php -l tests/framework/test_contract_registry.php
php -l tests/framework/test_strict_run_request.php
php -l tests/framework/run.php

bash -n lib/bash/doctor/contract_registry.sh

php scripts/contract.php validate --json
php scripts/contract.php check-doc docs/CONTRACT_REGISTRY.md
php tests/framework/test_contract_registry.php
php tests/framework/test_strict_run_request.php
php tests/framework/run.php
```

PowerShell:

```powershell
pwsh -NoProfile -NonInteractive -File tests/powershell/run.ps1
```

## Criterio PASS

- registro v2 válido;
- aliases ausentes del payload y del resolver;
- CLI posicional rechazada;
- selectores con kind incorrecto rechazados;
- `public_html` rechazado;
- env target legacy rechazado;
- doctor usa selector tipado;
- documentación generada coincide byte a byte;
- tests focales pasan;
- consumidores externos no fueron modificados.

## Criterio FAIL

- convertir un alias al nombre canónico;
- aceptar selector sin kind;
- mantener `TESTKIT_TARGET_*` como extensión;
- inferir group/category a partir del nombre;
- devolver `3` por una solicitud inválida;
- mezclar en este commit la normalización de store, stack o coverage;
- declarar suite completa verde sin ejecutarla.

## Rollback

Revertir el commit restaura el registro v1 y el target posicional anterior.

No actualizar el pin de `Base` hasta completar Fase 3 y la validación de consumidores.

## No verificado

- suite completa en checkout íntegro;
- CI remoto;
- ejecución real de PowerShell;
- Docker/MySQL;
- consumidores externos;
- integración mediante `Base`.

## Siguiente commit

Fase 3.2 — configuración estricta:

- eliminar aliases de selección;
- eliminar inferencias de store driver;
- eliminar aliases de stack;
- eliminar fallback y rutas legacy de coverage;
- actualizar docs y consumidores internos afectados.
