# Verificación — I6 command_spec v1

## Estado

`PENDIENTE_EJECUCION_CHECKOUT_COMPLETO`

## Implementación esperada

El corte I6 se considera implementado cuando:

```text
AgentActionPlanner
-> next_action.command_spec testkit.command_spec@1
-> CommandSpec::normalize()
-> AgentRunExecute admission
-> ProcessRunner argv/env/cwd
-> result.exit_code
-> AgentRunArtifact persiste decision + execution
```

La cadena `next_action.command` puede existir solo como presentación y no debe alimentar la ejecución.

## Gates focales

Desde el root de `testKit`:

```bash
php -l core/php/execution/CommandSpec.php
php -l core/php/reporting/agent/AgentActionPlanner.php
php -l core/php/reporting/AgentRunExecute.php
php -l tests/framework/test_command_spec_contract.php
php -l tests/framework/test_agent_command_spec_admission.php

php tests/framework/test_command_spec_contract.php
php tests/framework/test_agent_command_spec_admission.php
php tests/framework/test_agent_decision_contract.php
php tests/framework/test_agent_run_contract.php
```

## Gate framework

```bash
php tests/framework/run.php
```

## Gates estáticos mínimos

```bash
git status --short
git diff --check
find . -type f -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -r -n1 php -l
find bin scripts lib -type f \( -name '*.sh' -o -name 'testkit' \) -print0 | xargs -0 -r -n1 bash -n
```

PowerShell, si el entorno lo permite:

```powershell
pwsh -NoProfile -NonInteractive -File tests/powershell/run.ps1
```

## PASS

- el planner emite `command_spec` versionado para toda acción ejecutable;
- rerun usa `--suite` + `--test`, sin `TEST_MATCH*`;
- listado usa un selector público tipado;
- inspect usa argv exacto y declara `expects_json=true`;
- executor no reconstruye comandos desde `kind`;
- executor no parsea la cadena de presentación;
- schema/executor/cwd/env inválidos se rechazan antes de `ProcessRunner`;
- shell inline libre se rechaza;
- `TESTKIT_MODE=agent` queda explícito en `env` cuando aplica;
- specs y ejecución quedan persistidos por el artifact existente;
- focales y framework suite pasan.

## FAIL

- ejecutar una cadena shell como contrato primario;
- reintroducir targets posicionales o `TEST_MATCH*`;
- aceptar `bash -c`, `pwsh -Command` o equivalente dentro del contrato;
- ejecutar un spec inválido;
- mantener una segunda reconstrucción de argv dentro del executor;
- declarar I6 verificado solo por lint.

## Evidencia disponible en este corte

Antes de publicación se ejecutaron fuera de un checkout completo:

```text
php -l CommandSpec.php                         PASS
php -l AgentActionPlanner.php                  PASS
php -l AgentRunExecute.php                     PASS
php -l test_command_spec_contract.php          PASS
php -l test_agent_command_spec_admission.php   PASS
test_command_spec_contract.php aislado         PASS
AgentRunExecute con stubs de ProcessRunner     PASS
```

Estas comprobaciones validan sintaxis y comportamiento focal del contrato, pero **no sustituyen** `php tests/framework/run.php` sobre el checkout completo.

## No verificado todavía

- suite framework completa;
- CI remoto;
- PowerShell real;
- Docker/MySQL;
- consumidores externos;
- Base/Pruebas/gitlinks.
