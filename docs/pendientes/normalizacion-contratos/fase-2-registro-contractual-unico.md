# Fase 2 — Registro contractual único

## Estado

Implementado en `testKit`.

Consumidores externos no modificados. Los aliases heredados se registran como deuda explícita y se eliminan en Fase 3; no se agregaron aliases nuevos.

## Baseline

```text
Repositorio: lucasborges2001/testKit
Rama base: main
Commit base auditado: b5b09284c69728dfa93266300f405e3e57157684
Rama de trabajo: agent/testkit-contract-normalization
Commit anterior: e9eb85c97ffd0d97951a3be99999be7eb4360cc7
Fecha: 2026-07-28
```

## Objetivo

Concentrar en una sola autoridad ejecutable:

- suites y nombres públicos;
- grupos;
- categorías;
- aliases heredados y fase de eliminación;
- capacidades y restricciones por suite;
- support matrix;
- ayuda del runner;
- superficie `config-schema`;
- validación de targets en doctor;
- documentación generada y control de drift.

## Autoridad

```text
core/php/config/ContractRegistry.php
```

Las demás superficies son consumidores:

```text
TargetResolver
SuiteContractRegistry
runTest.php --help
scripts/inspect.php config-schema
scripts/contract.php
Doctor Bash
Doctor PowerShell
docs/CONTRACT_REGISTRY.md
```

No se permite mantener listas públicas paralelas en los nuevos componentes.

## Decisiones

1. Los suite IDs permanecen internos con underscore; los nombres públicos canónicos usan guion.
2. Grupos y categorías son tipos distintos dentro del registro.
3. Los aliases existentes siguen resolviendo solo durante Fase 2 y están marcados `deprecated=true`, `remove_in_phase=3`.
4. `TESTKIT_TARGET_*` se conserva únicamente para no mezclar Fase 2 con la eliminación de configuración prevista en Fase 3; sus suite IDs se validan contra el registro.
5. La documentación pública se genera desde el registro y CI falla si difiere.
6. Doctor Bash y PowerShell consultan `scripts/contract.php`; no mantienen una tercera lista efectiva.
7. `SuiteContractRegistry` queda como adapter interno para callers existentes, sin duplicar capacidades o hazards.
8. No existe referencia a Tarifa ni a otro dominio de negocio.

## Archivos afectados

```text
A core/php/config/ContractRegistry.php
M core/php/config/SuiteContractRegistry.php
M core/php/bootstrap.php
M core/php/suites/TargetResolver.php
M runners/runTest.php
A scripts/contract.php
M scripts/inspect.php
M lib/bash/doctor.sh
A lib/bash/doctor/contract_registry.sh
M lib/powershell/Doctor.ps1
A lib/powershell/Doctor.ContractRegistry.ps1
A docs/CONTRACT_REGISTRY.md
M tests/framework/run.php
A tests/framework/test_contract_registry.php
A docs/pendientes/normalizacion-contratos/fase-2-registro-contractual-unico.md
```

## Invariantes

- cada suite pública tiene exactamente un suite ID;
- cada target resuelve solo suites registradas;
- cada alias apunta a un nombre canónico no alias;
- help, schema, resolver, doctor y documentación leen el registro;
- capacidades y restrictions de runners salen del registro;
- el digest cambia ante cualquier cambio contractual;
- un archivo de self-test registrado que falta es fallo, no `SKIP`;
- ninguna superficie contiene lógica de dominio.

## Validación

```bash
php -l core/php/config/ContractRegistry.php
php -l core/php/config/SuiteContractRegistry.php
php -l core/php/suites/TargetResolver.php
php -l runners/runTest.php
php -l scripts/contract.php
php -l scripts/inspect.php
php -l tests/framework/test_contract_registry.php
php -l tests/framework/run.php

bash -n lib/bash/doctor.sh
bash -n lib/bash/doctor/contract_registry.sh

php scripts/contract.php validate --json
php scripts/contract.php check-doc docs/CONTRACT_REGISTRY.md
php tests/framework/test_contract_registry.php
php tests/framework/run.php
```

PowerShell/CI:

```powershell
pwsh -NoProfile -NonInteractive -File tests/powershell/run.ps1
php tests/framework/run.php
```

## Criterio PASS

- registro válido sin errores;
- resolver coincide con todas las definiciones;
- help y config-schema exponen el digest del registro;
- doctor acepta y clasifica mediante el CLI contractual;
- documento generado coincide byte a byte;
- self-test de paridad está registrado y no puede desaparecer silenciosamente;
- CI ejecuta el self-test en Linux y Windows;
- no se modificaron consumidores externos.

## Riesgos

- callers externos todavía pueden depender de aliases que Fase 3 eliminará;
- `MetaRunner` conserva mensajes históricos y será endurecido con la CLI estricta de Fase 3;
- doctor sigue conteniendo lógica de checks por capacidad; solo la identidad y clasificación de target se centralizan aquí;
- PowerShell real y Docker Desktop requieren CI o host Windows.

## Rollback

Revertir este commit restaura las listas y capacidades distribuidas anteriores. No actualizar `Base` ni otros pins mientras la rama mantenga fases incompatibles pendientes.

## No verificado

- suite completa del repositorio en checkout limpio;
- CI remoto;
- Docker/MySQL;
- ejecución real de PowerShell y Docker Desktop;
- consumidores externos;
- integración mediante el gitlink de `Base`.

## Próxima fase

Fase 3 — CLI y configuración estrictas:

- separar suite, group y category mediante flags explícitos;
- eliminar aliases de targets;
- eliminar `TESTKIT_TARGET_*`;
- eliminar inferencias y aliases de store/stack/selection/coverage;
- normalizar códigos de request inválido.
