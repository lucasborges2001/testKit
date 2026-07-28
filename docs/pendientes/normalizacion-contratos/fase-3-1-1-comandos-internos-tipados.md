# Fase 3.1.1 — Comandos internos tipados

## Estado

Implementado en `testKit`.

Este corte migra únicamente los comandos que el propio framework genera para rerun, listado, trace y acciones del agente. No modifica todavía workflows, Makefile, scripts operativos, UI ni aliases de configuración.

## Baseline

```text
Repositorio: lucasborges2001/testKit
Rama base: main
Commit base auditado: b5b09284c69728dfa93266300f405e3e57157684
Rama de trabajo: agent/testkit-contract-normalization
Commit anterior: 43781b1b25aad4f2bba963be3cac94571dd03361
Fecha: 2026-07-28
```

## Problema verificado

La Fase 3.1 eliminó targets posicionales y `TEST_MATCH`, pero varios productores internos seguían emitiendo comandos incompatibles:

```text
php runTest.php back-php
TEST_MATCH=<file> php runTest.php back-php
```

Esos comandos fallan contra `RunRequest` aunque la selección original sea válida.

## Contrato aplicado

Toda sugerencia de suite usa:

```text
php runTest.php --suite <suite>
php runTest.php --suite <suite> --test <repo-relative>
php runTest.php --suite <suite> --list
```

Los comandos de inspección y reporte conservan sus entrypoints actuales.

## Cambios

- `SuggestedCommandBuilder` emite `--suite` y `--test`.
- `CommandSuggestion` deja de publicar `TEST_MATCH`.
- `AgentActionPlanner` solo propone rerun si existe una suite canónica.
- El agente deriva un selector tipado para `list_tests` y usa `--group all` solo como último fallback explícito.
- Los helpers Bash y PowerShell producen la misma gramática.
- Los self-tests verifican ausencia de `TEST_MATCH` en comandos generados.
- El runner de self-tests registra los tests de comandos wrapper que antes existían pero no se ejecutaban desde `tests/framework/run.php`.

## Archivos afectados

```text
M core/php/reporting/SuggestedCommandBuilder.php
M core/php/reporting/CommandSuggestion.php
M core/php/reporting/agent/AgentActionPlanner.php
M lib/bash/rewrite.sh
M lib/powershell/Rewrite.ps1
M tests/framework/run.php
M tests/framework/test_agent_decision_contract.php
M tests/framework/test_console_reporter_wrapper_commands.php
M tests/framework/test_meta_action_required_renderer.php
M tests/framework/test_meta_rerun_plan_fallback.php
M tests/framework/test_reporting_contract.php
M tests/framework/test_suggested_command_builder_invokers.php
M tests/powershell/test_command_rewrite.ps1
A docs/pendientes/normalizacion-contratos/fase-3-1-1-comandos-internos-tipados.md
```

## Invariantes

1. un rerun generado siempre declara `--suite`;
2. un archivo aislado siempre se expresa con `--test`;
3. ninguna sugerencia nueva usa `TEST_MATCH`;
4. un agente no inventa una suite cuando no puede derivarla;
5. Bash y PowerShell publican la misma selección lógica;
6. los comandos de reporte e inspección no cambian;
7. no se reintroducen aliases;
8. no se modifica el contrato de store, stack o coverage.

## Validación

```bash
php -l core/php/reporting/SuggestedCommandBuilder.php
php -l core/php/reporting/CommandSuggestion.php
php -l core/php/reporting/agent/AgentActionPlanner.php
php -l tests/framework/test_agent_decision_contract.php
php -l tests/framework/test_console_reporter_wrapper_commands.php
php -l tests/framework/test_meta_action_required_renderer.php
php -l tests/framework/test_meta_rerun_plan_fallback.php
php -l tests/framework/test_reporting_contract.php
php -l tests/framework/test_suggested_command_builder_invokers.php
php -l tests/framework/run.php

bash -n lib/bash/rewrite.sh
php tests/framework/test_reporting_contract.php
php tests/framework/test_console_reporter_wrapper_commands.php
php tests/framework/test_suggested_command_builder_invokers.php
php tests/framework/test_meta_action_required_renderer.php
php tests/framework/test_meta_rerun_plan_fallback.php
php tests/framework/test_agent_decision_contract.php
php tests/framework/run.php
```

PowerShell:

```powershell
pwsh -NoProfile -NonInteractive -File tests/powershell/run.ps1
```

## Criterio PASS

- comandos de suite contienen `--suite`;
- reruns focalizados contienen `--test`;
- no aparece `TEST_MATCH=` en comandos producidos por reporting o agent;
- self-tests focales pasan;
- sintaxis PHP/Bash válida;
- PowerShell parsea y sus tests pasan en CI Windows;
- no se modifican consumidores externos.

## Criterio FAIL

- conservar un target posicional en una recomendación ejecutable;
- convertir aliases a nombres canónicos silenciosamente;
- usar `TEST_MATCH` como salida pública;
- emitir `--group` o `--category` por inferencia ambigua;
- declarar todo Fase 3 cerrado con workflow o UI todavía sin migrar.

## Rollback

Revertir este commit restaura los productores de comandos anteriores. El registro v2 y el parser estricto de Fase 3.1 permanecen sin cambios.

## No verificado

- suite completa en checkout íntegro;
- CI remoto Linux/Windows;
- ejecución real de PowerShell;
- Docker/MySQL;
- consumidores externos;
- integración mediante `Base`.

## Siguiente commit

Fase 3.1.2 — cutover operativo interno:

- `.github/workflows/ci.yml`;
- `Makefile`;
- scripts `migration_contract*`;
- scripts `test.sh` y `test.ps1`;
- UI PowerShell y sus tests;
- ejemplos operativos ejecutables todavía posicionales.
