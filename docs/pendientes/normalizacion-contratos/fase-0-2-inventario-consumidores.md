# Fase 0.2 — Inventario de consumidores del contrato

## Estado

Inventario documental completado.

Runtime no modificado.

Este documento registra consumidores observados mediante búsqueda de código y metadata de GitHub sobre ramas por defecto indexadas. No declara cobertura exhaustiva de checkouts locales, ramas no indexadas, forks, secretos, variables configuradas fuera del repositorio ni automatizaciones privadas.

## Baseline

```text
Repositorio: lucasborges2001/testKit
Rama base: main
Commit base: b5b09284c69728dfa93266300f405e3e57157684
Rama de trabajo: agent/testkit-contract-normalization
Commit Fase 0.1: 25cebc594a49c366d6cd65829c9fd4b240fbcf53
Fecha de inspección: 2026-07-28
```

## Objetivo

Identificar qué repositorios y archivos consumen la superficie actual de `testKit` antes de retirar aliases, variables heredadas, entrypoints directos, schemas duplicados o comportamientos específicos por plataforma.

Cada consumidor debe permitir responder:

1. qué contrato consume;
2. si lo ejecuta o solo lo documenta;
3. qué cambio requerirá;
4. cuándo debe adaptarse respecto del cutover;
5. qué evidencia falta.

## Convenciones

### Tipo de consumidor

| Tipo | Significado |
|---|---|
| `RUNTIME` | Ejecuta `testKit` o interpreta sus artifacts. |
| `CI` | Lo invoca desde automatización o scripts de validación. |
| `CONFIG` | Define variables o archivos que alteran su ejecución. |
| `AGENT` | Da instrucciones a Codex, Claude CLI u otro agente. |
| `DOCS` | Publica comandos o semántica para operadores. |
| `PIN` | Fija el SHA o distribuye el checkout de `testKit`. |

### Estado de evidencia

| Estado | Significado |
|---|---|
| `VERIFIED_PATH` | La búsqueda encontró un archivo concreto en un SHA concreto. |
| `VERIFIED_METADATA` | PR o commit describe una integración o ejecución concreta. |
| `PARTIAL` | Se verificó una referencia, pero no toda su semántica. |
| `NOT_VERIFIED` | Requiere inspección adicional antes del cutover. |

### Acción

| Acción | Significado |
|---|---|
| `ADAPT_BEFORE_CUTOVER` | Debe cambiar antes de integrar el contrato nuevo. |
| `ADAPT_WITH_CUTOVER` | Puede cambiar en el mismo corte coordinado. |
| `UPDATE_DOCS_AFTER_RUNTIME` | Solo se actualiza cuando la nueva superficie ya es estable. |
| `REMOVE_COUPLING` | Debe eliminarse una dependencia indebida. |
| `VERIFY_ONLY` | No se prescribe cambio sin más evidencia. |

---

## 1. Consumidores internos de testKit

Estos consumidores pertenecen al mismo repositorio y deben cambiar antes de adaptar repositorios externos.

| ID | Área | Archivos representativos | Contrato consumido | Acción |
|---|---|---|---|---|
| INT-01 | resolución de targets | `core/php/suites/TargetResolver.php`, `core/php/suites/MetaRunner.php` | aliases, grupos, categorías y overrides `TESTKIT_TARGET_*` | `ADAPT_BEFORE_CUTOVER` |
| INT-02 | ayuda y entrada PHP | `runners/runTest.php` | lista manual de targets y flags | `ADAPT_BEFORE_CUTOVER` |
| INT-03 | schema de configuración | `core/php/config/ConfigSchema.php` | targets, variables, aliases y versiones | `ADAPT_BEFORE_CUTOVER` |
| INT-04 | wrappers Linux | `bin/testkit`, `lib/bash/*.sh` | compose passthrough, env, rewrite y build policy | `ADAPT_BEFORE_CUTOVER` |
| INT-05 | wrappers Windows | `bin/testkit.ps1`, `lib/powershell/*.ps1` | env, rewrite, compose y comandos PowerShell | `ADAPT_BEFORE_CUTOVER` |
| INT-06 | selección | `core/php/discovery/TestSelection.php`, runners de suites | `TEST_MATCH*` y precedencias | `ADAPT_BEFORE_CUTOVER` |
| INT-07 | store | `core/php/store/StoreRegistry.php` | `TEST_STORE_DRIVER`, aliases e inferencias | `ADAPT_BEFORE_CUTOVER` |
| INT-08 | coverage | `core/php/common/Paths.php`, suites y cleanup | `TEST_COVERAGE_ROOT`, `TEST_COVERAGE_DIR` y paths legacy | `ADAPT_BEFORE_CUTOVER` |
| INT-09 | agente planner | `core/php/reporting/agent/AgentActionPlanner.php` | action kinds y strings Bash | `ADAPT_BEFORE_CUTOVER` |
| INT-10 | agente executor | `core/php/reporting/AgentRunExecute.php` | action kinds rederivados y exit `0` para no-op | `ADAPT_BEFORE_CUTOVER` |
| INT-11 | reporting | `core/php/reporting/CanonicalReport.php` y loaders | campos duplicados, fallbacks y versiones | `ADAPT_BEFORE_CUTOVER` |
| INT-12 | UI PowerShell | `ui/powershell/Testkit.UI.ps1`, `ui/powershell/lib/Testkit.UI.Plan.ps1` | selección y comandos actuales | `ADAPT_BEFORE_CUTOVER` |
| INT-13 | self-tests | `tests/framework/`, `tests/powershell/` | contrato vigente y tolerancia a tests ausentes | `ADAPT_BEFORE_CUTOVER` |
| INT-14 | CI | `.github/workflows/ci.yml` | comandos, matrices y claims de paridad | `ADAPT_BEFORE_CUTOVER` |
| INT-15 | documentación | `README.md`, `AGENTS.md`, `SUPPORT_MATRIX.md`, `docs/*.md` | superficie pública repetida | `UPDATE_DOCS_AFTER_RUNTIME` |
| INT-16 | dominio Tarifa | `core/php/tarifa/`, target `tarifa-contract`, inspect `tarifa_evidence` | fixtures y reglas de negocio | `REMOVE_COUPLING` |

### Dependencia interna crítica

La secuencia obligatoria es:

```text
registro contractual único
-> resolver/config/help
-> planner/executor/reporting
-> wrappers
-> tests de contrato
-> documentación
-> consumidores externos
```

No debe adaptarse un consumidor externo contra una API objetivo que todavía no esté expresada por un registro y schema ejecutables.

---

## 2. Topología de distribución

### 2.1 Base como pin y distribuidor

Se verificó en `lucasborges2001/Base` el commit:

```text
7344c83d7b16cc0f503ac37df7c14f96af2264a8
Update subproject commit reference for testkit
```

Además se encontraron referencias en:

```text
scripts/ci/base-host-ci.sh
scripts/ci/base-testkit-doctor.sh
scripts/ci/base-testkit-reference.sh
scripts/ci/base-testkit-down.sh
scripts/ci/base-ci-env.sh
docs/workflows/ci.md
```

Clasificación:

| Campo | Valor |
|---|---|
| Tipos | `PIN`, `CI`, `CONFIG`, `DOCS` |
| Evidencia | `VERIFIED_PATH` y `VERIFIED_METADATA` |
| Riesgo | hosts que consumen `Base/testkit` pueden heredar el corte sin modificar su gitlink directo |
| Acción | `ADAPT_BEFORE_CUTOVER` |

### Decisión

`Base` debe ser tratado como consumidor prioritario y como punto de propagación. El orden correcto no es actualizar primero los hosts:

```text
testKit branch candidate
-> Base adapta scripts y fija candidate SHA
-> hosts prueban Base candidate
-> testKit cutover
-> Base actualiza pin final
-> hosts actualizan gitlink de Base
```

Actualizar solo `testKit/main` dejaría scripts de Base y hosts apuntando a semántica anterior.

---

## 3. Consumidores externos verificados

## 3.1 Pruebas

Baseline observado en búsqueda:

```text
Repositorio: lucasborges2001/Pruebas
SHA: aa167b14128c03e7bfe58ba1fa88ebe3dd853c1d
```

Rutas encontradas:

```text
README.md
docs/operacion/testing.md
docs/operacion/browser-e2e.md
docs/operacion/sql-observability.md
.claude/skills/pruebas-verify/SKILL.md
pruebasDocker/scripts/e2e.sh
pruebasDocker/scripts/smoke.sh
profiles/sistemaCargador/scripts/cargador/verifyLocal.sh
profiles/sistemaCargador/scripts/cargador/verifyCorte01.sh
scripts/smoke/cargador_sesion_corte4a.php
scripts/smoke/cargador_sesion_corte4d/Support.php
```

Contratos observados por búsqueda:

- invocación de `bin/testkit`;
- selección mediante `TEST_MATCH`;
- documentación operativa de targets;
- lectura o presencia de `canonical_report` en smokes;
- instrucciones específicas para agentes;
- rutas especiales de browser y SQL observability.

Clasificación:

| Campo | Valor |
|---|---|
| Tipos | `RUNTIME`, `CI`, `AGENT`, `DOCS` |
| Evidencia | `VERIFIED_PATH`; semántica completa `PARTIAL` |
| Acción | `ADAPT_BEFORE_CUTOVER` |
| Riesgo principal | alta superficie y dependencia indirecta mediante `submodules/Base/testkit` |

Cambios esperados:

1. reemplazar `TEST_MATCH` por selección exacta;
2. reemplazar comandos raw por CLI v2;
3. adaptar parser de reportes al schema raíz v2;
4. actualizar la skill de Claude para no recomendar comandos retirados;
5. validar SQL observability bajo la misma ruta contractual Linux/Windows;
6. fijar explícitamente los SHAs de `Base` y `testKit` usados en la validación.

Metadata adicional verificada:

El PR `Pruebas#81` registra el merge de `testKit#1`, pero declara que `submodules/Base/testkit` no fue actualizado en ese corte. Por lo tanto, una validación contra un checkout separado de TestKit no demuestra que el host use ese mismo SHA mediante su topología real.

## 3.2 Base

Baseline observado:

```text
Repositorio: lucasborges2001/Base
SHA: 7344c83d7b16cc0f503ac37df7c14f96af2264a8
```

Rutas verificadas:

```text
scripts/ci/base-host-ci.sh
scripts/ci/base-testkit-reference.sh
scripts/ci/base-testkit-doctor.sh
scripts/ci/base-testkit-down.sh
scripts/ci/base-ci-env.sh
docs/workflows/ci.md
```

Contratos observados:

- `bin/testkit`;
- `reference-contract`;
- `TEST_STORE_DRIVER`;
- lifecycle de doctor/down;
- pin del subproyecto.

Clasificación:

| Campo | Valor |
|---|---|
| Tipos | `PIN`, `CI`, `CONFIG`, `DOCS` |
| Evidencia | `VERIFIED_PATH` |
| Acción | `ADAPT_BEFORE_CUTOVER` |
| Prioridad | `P0 externo` |

Cambios esperados:

1. adaptar scripts al CLI v2;
2. conservar `reference-contract` como nombre único, sin aliases;
3. declarar driver exacto sin inferencia;
4. validar códigos de salida cerrados;
5. actualizar gitlink solo después de validar el candidate.

## 3.3 locker

Baseline observado:

```text
Repositorio: lucasborges2001/locker
SHA: d3a1dc3ef62a1ee09e977f044925053d781ff21b
```

Rutas verificadas:

```text
docs/operacion/testing.md
test/.env.test.example
scripts/run_smoke_mysql.sh
scripts/run_plc_agent_local_e2e_testkit.sh
scripts/run_plc_agent_open_cycle_e2e_testkit.sh
back/plc/plcTypes.php
```

Contratos observados:

- `bin/testkit`;
- `reference-contract`;
- `TEST_STORE_DRIVER`;
- configuración de entorno;
- `canonical_report` aparece en código PHP y requiere inspección semántica antes del cambio de schema.

Clasificación:

| Campo | Valor |
|---|---|
| Tipos | `RUNTIME`, `CONFIG`, `DOCS` |
| Evidencia | `VERIFIED_PATH`; parser de reporte `PARTIAL` |
| Acción | `ADAPT_BEFORE_CUTOVER` |

Cambios esperados:

1. ejecutar smokes con driver explícito;
2. adaptar comandos al CLI v2;
3. revisar si `plcTypes.php` interpreta reportes de TestKit o usa el nombre para otro contrato;
4. validar scripts de E2E sin depender de aliases.

## 3.4 BasePLC

Baseline observado:

```text
Repositorio: lucasborges2001/BasePLC
SHA: 306f1bd1790501c453523e42b0f597de47be7d61
```

Rutas verificadas:

```text
README.md
docs/integraciones/base-testkit.md
docs/integraciones/testkit-hosts.md
docs/operacion/inventario-multi-plc.md
test/.env.test
```

Contratos observados:

- invocación/documentación de `bin/testkit`;
- `TEST_STORE_DRIVER` en configuración;
- integración de TestKit con hosts.

Clasificación:

| Campo | Valor |
|---|---|
| Tipos | `CONFIG`, `DOCS`; ejecución real `NOT_VERIFIED` |
| Evidencia | `VERIFIED_PATH` |
| Acción | `ADAPT_WITH_CUTOVER` |

## 3.5 riegos

Baseline observado:

```text
Repositorio: lucasborges2001/riegos
SHA: 55ae58102d93b8b6f9eaca2f50641f63b82dd1bc
```

Ruta verificada:

```text
scripts/run_testkit_doctor.sh
```

Contrato observado:

- wrapper específico para ejecutar doctor de TestKit.

Clasificación:

| Campo | Valor |
|---|---|
| Tipos | `CI` u operación local |
| Evidencia | `VERIFIED_PATH` |
| Acción | `ADAPT_WITH_CUTOVER` |

Cambio esperado:

Migrar al verbo canónico `testkit doctor` y verificar `--readonly --json` cuando el contrato v2 lo implemente.

## 3.6 wemobFirmware

Baseline observado:

```text
Repositorio: lucasborges2001/wemobFirmware
SHA: 221c7df958fc0b320774eefc60448df52f25921d
```

Rutas verificadas:

```text
docs/cambios/2026-07-07-testkit-runner.md
docs/cambios/2026-07-07-refactor-command-queue-v2.md
test/testkit_contract/command_queue_cases.py
```

Contrato observado:

- documentación de runner TestKit;
- referencias a `TEST_STORE_DRIVER`;
- casos Python denominados como contrato TestKit.

Clasificación:

| Campo | Valor |
|---|---|
| Tipos | `DOCS`, tests propios; integración directa `PARTIAL` |
| Evidencia | `VERIFIED_PATH` |
| Acción | `VERIFY_ONLY` antes de prescribir cambios |

## 3.7 sistemaCargador

Baseline observado:

```text
Repositorio: lucasborges2001/sistemaCargador
SHA: f1e8fa7416250accc07c81e5ddf64f6b4d8b7953
```

Rutas encontradas:

```text
CLAUDE.md
web_cargadores/PHP/mysql/mysqlBack.md
web_cargadores/PHP/estadoVivo/estadoVivoBack.md
web_cargadores/PHP/alerta/alertaBack.md
web_cargadores/PHP/sesion/sesionBack.md
web_cargadores/public/auth/authFront.md
web_cargadores/public/alerta/alertaFront.md
docs/pendientes/cobros/03_fases_de_implementacion.md
```

Contratos observados:

- referencias documentales a `bin/testkit` y `TEST_MATCH`;
- instrucciones de agente y documentación histórica.

Clasificación:

| Campo | Valor |
|---|---|
| Tipos | principalmente `AGENT` y `DOCS` |
| Evidencia | `VERIFIED_PATH`; ejecución actual `NOT_VERIFIED` |
| Acción | `UPDATE_DOCS_AFTER_RUNTIME` |

No debe modificarse documentación histórica de dominio como sustituto de adaptar scripts ejecutables reales.

## 3.8 Tarifa

Evidencia verificada por metadata:

```text
testKit PR #1
Tarifa PR #8 head usado: 6808f63c7de6b5fb506575176a3c99ed218317e7
Comando registrado: php runTest.php tarifa-contract
```

Clasificación:

| Campo | Valor |
|---|---|
| Tipos | integración contractual temporal |
| Evidencia | `VERIFIED_METADATA` |
| Acción | `REMOVE_COUPLING` |

Decisión:

- `Tarifa` conserva sus fixtures, builders y asserts de dominio;
- `testKit` conserva runners y evidencia genérica;
- Tarifa debe ejecutarse por una suite genérica y selección exacta;
- no debe existir un target `tarifa-contract` en el registro público de TestKit;
- no debe existir `tarifa_evidence` como campo específico del inspector genérico.

La eliminación exige verificar primero dónde residirá el soporte extraído y qué comando genérico reproducirá la prueba que hoy usa el target especial.

---

## 4. Matriz contrato → consumidores

| Contrato que cambiará | Consumidores verificados | Riesgo | Orden |
|---|---|---|---|
| `bin/testkit` y raw compose | Base, Pruebas, locker, BasePLC, riegos, sistemaCargador docs | alto | CLI v2 primero; adapters externos después |
| targets y aliases | Base, Pruebas, locker, documentación interna | alto | registry primero; scripts externos después |
| `TEST_MATCH` | Pruebas, sistemaCargador docs, runtime interno | alto | selección exacta implementada antes de borrar |
| `TEST_STORE_DRIVER` e inferencias | Base, locker, BasePLC, wemobFirmware | alto | mantener nombre canónico; retirar fuentes alternativas |
| `reference-contract` aliases | Base, locker, docs internas | medio | conservar token canónico; actualizar cualquier alias |
| `TEST_COVERAGE_DIR` | solo referencias internas encontradas | medio | retirar internamente; búsqueda externa no halló resultados |
| `canonical_report` v1 | Pruebas, locker, runtime interno | alto | publicar schema v2 y adaptar parsers antes del corte |
| action kinds/agente | testKit y agentes guiados por AGENTS/skills | crítico | planner y executor primero |
| códigos de salida | scripts CI externos y wrappers | alto | tabla v2 antes de adaptar consumidores |
| `tarifa-contract` | testKit/Tarifa por metadata | crítico arquitectónico | extraer dominio antes del registro v2 |

---

## 5. Consumidores no verificados

No se pudo demostrar exhaustivamente:

1. checkouts locales fuera de GitHub;
2. ramas no indexadas o pendientes sin merge;
3. forks y repositorios fuera de `lucasborges2001`;
4. GitHub Actions que construyan comandos dinámicamente sin contener tokens buscados;
5. `.env` reales excluidos por Git;
6. aliases configurados en variables de organización, secrets o runners self-hosted;
7. scripts que invoquen TestKit mediante rutas calculadas;
8. parsers externos que lean artifacts sin usar el texto `canonical_report`;
9. herramientas locales de Codex o Claude no versionadas;
10. consumidores de códigos de salida que solo comparen valores numéricos.

Estos puntos no autorizan compatibilidad. Exigen validación coordinada antes del cutover.

---

## 6. Plan de adaptación coordinada

## Paso A — Candidate interno

En `testKit`:

1. extraer Tarifa;
2. publicar registro contractual único;
3. implementar protocolo agente v2;
4. implementar CLI y schema v2;
5. cerrar paridad Bash/PowerShell;
6. ejecutar tests internos.

No actualizar consumidores todavía.

## Paso B — Base candidate

En una rama de `Base`:

1. fijar el SHA candidate de TestKit;
2. adaptar scripts CI;
3. validar doctor, reference-contract y suite focalizada;
4. registrar resultados exactos;
5. no mergear todavía el pin final.

## Paso C — Hosts prioritarios

Contra el candidate de Base/TestKit:

1. `Pruebas`;
2. `locker`;
3. `BasePLC`;
4. `riegos`;
5. otros consumidores confirmados.

Cada host debe demostrar:

- comando v2;
- selección exacta;
- códigos de salida;
- artifacts v2;
- no uso de aliases;
- SHA efectivo de TestKit.

## Paso D — Cutover

```text
testKit v2 merge
-> Base pin final
-> consumidores actualizan Base/TestKit
-> documentación externa
-> eliminación de branches temporales autorizada por repositorio
```

No mantener doble runtime entre los pasos.

---

## 7. Criterio PASS de Fase 0.2

La fase se considera `PASS` documental porque:

- se identificaron consumidores internos;
- se identificó a `Base` como distribuidor del gitlink;
- se registraron consumidores externos con repositorio, SHA y rutas;
- se separaron consumidores ejecutables de referencias documentales;
- se mapearon contratos afectados y orden de adaptación;
- las áreas sin evidencia quedaron explícitamente como no verificadas;
- no se modificó runtime.

## 8. Criterio de cierre de Fase 0 completa

Fase 0 todavía no está cerrada.

Pendientes mínimos:

1. inventario reproducible de códigos de salida por entrypoint;
2. inventario de artifacts y schemas con ownership de cada campo;
3. matriz de paridad Bash/PowerShell por vector de entrada;
4. ADR del cutover sin compatibilidad;
5. resolución de todos los elementos `VERIFY` de la Fase 0.1;
6. comandos de baseline ejecutables en checkout limpio.

## 9. Validación documental

Validación requerida para este commit:

```bash
git diff --check main...agent/testkit-contract-normalization
git diff --name-status main...agent/testkit-contract-normalization
git diff --stat main...agent/testkit-contract-normalization
```

Resultado esperado:

```text
solo archivos Markdown agregados bajo docs/pendientes/normalizacion-contratos/
sin código
sin workflows
sin deletes
sin renames
sin gitlinks
```

## 10. Rollback

Revertir únicamente el commit que agrega este documento o borrar la rama antes de mergear.

No hay rollback operativo porque no se modificó runtime, configuración ni consumidores externos.
