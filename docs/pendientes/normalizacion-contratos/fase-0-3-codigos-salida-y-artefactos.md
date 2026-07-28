# Fase 0.3 — Inventario de códigos de salida y artefactos

## Estado

Inventario documental completado.

Runtime no modificado.

Este documento separa estrictamente:

- comportamiento observado en el baseline;
- contradicciones y ambigüedades;
- decisión contractual objetivo para fases posteriores.

Nada marcado como destino contractual está implementado por este commit.

## Baseline

```text
Repositorio: lucasborges2001/testKit
Rama base: main
Commit base: b5b09284c69728dfa93266300f405e3e57157684
Rama de trabajo: agent/testkit-contract-normalization
Commit Fase 0.1: 25cebc594a49c366d6cd65829c9fd4b240fbcf53
Commit Fase 0.2: 70f6b6e399bb412092d2a63992085167016446af
Fecha de inspección: 2026-07-28
```

## Objetivo

Congelar dos superficies que actualmente se superponen:

1. significado de los códigos de salida;
2. estructura, versionado y ubicación de los artefactos.

El resultado debe permitir que un agente decida sin interpretar texto de consola:

```text
qué operación se ejecutó
qué resultado contractual produjo
si la evidencia es válida
qué artifact debe consumir
qué acción puede ejecutar después
```

## Convenciones

### Decisión

| Decisión | Significado |
|---|---|
| `KEEP` | El concepto puede permanecer con el mismo significado. |
| `REMAP` | La capacidad permanece, pero debe usar el código o schema único definido por el contrato v2. |
| `DELETE` | La superficie duplicada, alias o fallback debe desaparecer. |
| `INTERNAL` | Puede permanecer como detalle de implementación, no como contrato público. |
| `VERIFY` | Falta ejecución o inspección adicional antes de cerrar Fase 0. |

### Tipo de artefacto

| Tipo | Significado |
|---|---|
| `EVIDENCE` | Evidencia inmutable asociada a una corrida. |
| `POINTER` | Puntero mutable al artifact más reciente. No es evidencia por sí mismo. |
| `INDEX` | Índice resumido de artifacts o corridas. |
| `DIAGNOSTIC` | Diagnóstico derivado; no reemplaza la evidencia fuente. |
| `HISTORY` | Estado histórico acumulado entre corridas. |
| `HUMAN` | Presentación para personas, no contrato de máquina. |
| `INTEROP` | Formato para tooling externo como JUnit o SARIF. |

---

# Parte A — Códigos de salida

## 1. Tabla observada por entrypoint

### 1.1 `php runTest.php`

Archivos principales:

- `runners/runTest.php`
- `core/php/suites/MetaRunner.php`
- `core/php/execution/SuiteExecutor.php`
- `core/php/suites/SuiteOrchestrator.php`

| Código observado | Productor | Significado actual | Decisión |
|---:|---|---|---|
| `0` | help, list, suite o meta | ayuda mostrada, selección listada o ejecución considerada exitosa | `REMAP` |
| `1` | suite/meta | tests fallidos o fallo meta general | `KEEP` como clase `TEST_FAILURE` |
| `2` | parser CLI | argumentos no soportados | `REMAP` a `INVALID_REQUEST` |
| `2` | suite | selección vacía cuando `TEST_REQUIRE_TESTS=0` | `REMAP`; no puede compartir significado con argumentos inválidos |
| `3` | suite | error operacional de suite | `REMAP` a `OPERATIONAL_ERROR` |
| `3` | MetaRunner | target inválido | `REMAP` a `INVALID_REQUEST` |
| `4` | suite especial | no pertenece a la tabla general de suites | `VERIFY` |
| `5` | SQL Observability | gate bloqueado | `KEEP` como clase `POLICY_BLOCKED` |

### Hallazgo EC-01 — El código `2` tiene dos significados

`SuiteExecutor` define:

```text
0 = pass
1 = fail
2 = skip
3 = error
```

Cuando no hay tests:

```text
TEST_REQUIRE_TESTS=1 -> 1
TEST_REQUIRE_TESTS=0 -> 2
```

Pero `runTest.php`, `inspect` y `agent-run` también usan `2` para errores de argumentos, resolución o serialización.

Consecuencia:

Un consumidor no puede saber solo por el código `2` si ocurrió:

- selección vacía tolerada;
- invocación inválida;
- artifact ausente;
- error de inspect;
- error de agent planning.

Decisión: `DELETE` de la semántica pública `2=skip`.

El estado `SKIP` debe vivir en el JSON de resultados por test o suite. El proceso solo debe devolver `0` si el resultado global es contractualmente exitoso.

### Hallazgo EC-02 — MetaRunner reinterpreta códigos de las suites

`MetaRunner` considera fallo cualquier child code distinto de `0` y `2`.

Por tanto:

```text
suite code 2 -> meta puede devolver 0
```

Esta transformación oculta la diferencia entre:

- suite ejecutada sin tests;
- suite omitida de forma válida;
- suite inválidamente vacía.

Decisión: `DELETE` de la excepción especial para child code `2`.

La agregación debe basarse en un resultado estructurado, no en una lista de excepciones numéricas.

### Hallazgo EC-03 — Target inválido y error operacional intercambian `2/3`

Actualmente:

- argumentos CLI inválidos: `2`;
- target inválido: `3`;
- error operacional de suite: `3`;
- error operacional SQL Observability: `2`.

No existe una clasificación transversal consistente.

Decisión: todos los entrypoints públicos deben usar la misma tabla v2.

---

## 2. `inspect`

Archivos principales:

- `scripts/inspect.php`
- `core/php/reporting/Inspector.php`
- `core/php/reporting/SeedStateInspector.php`

| Código observado | Significado actual | Decisión |
|---:|---|---|
| `0` | ayuda o payload producido | `KEEP` |
| `2` | comando desconocido, run inexistente, error de payload o serialización | `REMAP` según causa |

Problema:

El mismo código cubre request inválido y evidencia inexistente o no resoluble.

Destino:

```text
comando/flag inválido -> INVALID_REQUEST
run id inexistente -> EVIDENCE_INCOMPLETE
fallo de lectura/IO -> OPERATIONAL_ERROR
```

---

## 3. `agent-run`

Archivos principales:

- `core/php/reporting/AgentRun.php`
- `core/php/reporting/AgentRunExecute.php`
- `core/php/reporting/AgentRunArtifact.php`

| Código observado | Significado actual | Decisión |
|---:|---|---|
| `0` | plan generado, ayuda, acción no ejecutada o child exitoso | `REMAP` |
| `1` | child devuelve fallo de tests | `KEEP` |
| `2` | no se pudo construir decisión o registrar artifact | `REMAP` |
| cualquier child code | ejecución real | `KEEP` solo si pertenece a tabla v2 |

### Hallazgo EC-04 — Acción desconocida puede devolver falso éxito

El executor devuelve:

```text
executed=false
result.exit_code=0
```

para un action kind no reconocido.

El CLI devuelve ese `0` si el artifact pudo escribirse.

Consecuencia:

Una acción publicada por el planner puede no ejecutarse y aun así parecer exitosa.

Decisión:

```text
action no reconocida -> INVALID_REQUEST
acción declarada executable pero no ejecutada -> OPERATIONAL_ERROR
no_action explícita y válida -> 0
```

`no_action` debe ser una acción contractual explícita, no el caso por defecto.

---

## 4. SQL Observability y query gate

Archivos principales:

- `scripts/sql-observability/run.sh`
- `scripts/query_gate.php`
- `core/php/dbprofiling/gate/MysqlQueryGateConfig.php`
- `tests/framework/test_sql_observability_exit_code_5.php`

Tabla publicada actualmente:

| Código | Significado observado | Decisión |
|---:|---|---|
| `0` | evaluado sin bloqueo | `KEEP` |
| `2` | error operacional | `REMAP` a tabla común |
| `3` | contrato inválido | `REMAP` a tabla común |
| `4` | evidencia incompleta o incompatible | `KEEP` como clase común |
| `5` | policy/gate bloqueado | `KEEP` como clase común |

SQL Observability es la única ruta con una prueba end-to-end explícita que exige que el wrapper, MetaRunner y artifacts conserven exactamente el código `5`.

Ese comportamiento debe generalizarse como contrato de transporte de códigos, no mantenerse como excepción por suite.

### Hallazgo EC-05 — `sql-observability` tiene passthrough especial

`MetaRunner` contiene una rama específica para devolver el código de `sql_observability` sin colapsarlo a `1`.

Decisión:

- `KEEP` del transporte exacto de códigos públicos;
- `DELETE` del special-case por suite;
- todos los resultados deben transportar el código final desde el contrato común.

---

## 5. Definition of Done y herramientas auxiliares

`DefinitionOfDoneValidator` devuelve:

```text
0 = closable
1 = not closable
```

Esta semántica es válida como gate binario, pero el JSON debe diferenciar:

- checks fallidos;
- evidencia ausente;
- error operacional del evaluador.

Decisión: `KEEP` para `0/1` solo detrás del contrato común y con payload schema v2.

Los scripts auxiliares de profiling, cleanup, baseline y reportes deben inventariarse nuevamente al implementar Fase 6. No se asume que todos siguen la tabla SQL Observability.

Estado: `VERIFY`.

---

## 6. Tabla objetivo de proceso v2

Esta tabla queda fijada como decisión documental para la implementación posterior.

| Código | Nombre contractual | Significado único |
|---:|---|---|
| `0` | `OK` | La operación solicitada terminó y su resultado contractual es exitoso. |
| `1` | `TEST_FAILURE` | La ejecución fue válida y uno o más tests/checks funcionales fallaron. |
| `2` | `INVALID_REQUEST` | Comando, flag, target, suite, configuración o schema solicitado es inválido. |
| `3` | `OPERATIONAL_ERROR` | Falló infraestructura, IO, bootstrap, reporting, proceso hijo o dependencia requerida. |
| `4` | `EVIDENCE_INCOMPLETE` | La operación produjo o encontró evidencia insuficiente, incompatible o no verificable. |
| `5` | `POLICY_BLOCKED` | Un gate o policy válida bloqueó el resultado. |
| `6` | `NO_TESTS` | La selección válida no encontró tests y el contrato exige visibilidad explícita. |
| `7` | `CONTENTION` | La operación fue rechazada por lock, ownership o recurso concurrente. |
| `8` | `TIMEOUT` | La operación excedió el límite contractual. |

### Invariantes

1. Un código tiene un solo significado en todos los entrypoints.
2. Un significado tiene un solo código.
3. El wrapper devuelve el código del contrato común sin reinterpretarlo.
4. `suite_status`, `outcome_status` y `exit.code` deben ser coherentes.
5. `SKIP` por test no implica automáticamente código de proceso distinto de `0`.
6. Selección vacía siempre produce `NO_TESTS`; el caller decide mediante policy si es aceptable.
7. Un action kind desconocido nunca devuelve `0`.
8. Un error de escritura del artifact nunca puede ocultarse si el proceso iba a devolver `0`.
9. Los códigos no dependen del nombre de una suite.
10. El JSON contiene `exit.name` además de `exit.code`.

### Shape objetivo

```json
{
  "schema": {
    "name": "testkit.operation_result",
    "version": 2
  },
  "operation": "run",
  "exit": {
    "code": 1,
    "name": "TEST_FAILURE"
  },
  "status": "failed",
  "evidence_valid": true
}
```

---

# Parte B — Artefactos y schemas

## 7. Versiones observadas

| Superficie | Versión observada | Problema | Decisión |
|---|---|---|---|
| config inspect | `schema_version=5` | número sin namespace contractual común | `REMAP` |
| support contract | `support_contract_version=1` | convive con schema 5 | `DELETE` como versión paralela |
| suite report | `report_contract_version=2` | top-level amplio y con legacy | `REMAP` |
| runner contract | `runner_contract_version=1` | también anidado en `runner_contract.version` | `DELETE` duplicación |
| canonical report | `canonical_report.report_version=1` | duplica top-level | `DELETE` como subtree paralelo |
| agent decision | `contract_version=1` | top-level dice `deterministic_v2` | `REMAP` |
| agent execute artifact | `artifact_contract_version=2` | anida decisión completa y duplicados | `REMAP` |
| inspect | `inspect_contract=agent_decision_v1` | inspect queda acoplado a otro schema | `REMAP` |
| suite history | `suite_metrics_contract_version=1` | history sin schema root uniforme | `REMAP` |
| SQL profile | `mysql-query-profile-report-v2` | namespace propio válido | `KEEP` como schema especializado |
| SQL gate | `mysql-query-gate-report-v1` | namespace propio válido | `KEEP` como schema especializado |
| SARIF | `2.1.0` | estándar externo | `KEEP` |

### Hallazgo AR-01 — La palabra “canonical” identifica una copia

El reporte persistido mantiene campos top-level y agrega otro árbol `canonical_report` con:

- status;
- selection;
- summary;
- diagnostics;
- evidence;
- artifacts;
- seed state;
- agent summary;
- warnings;
- runner.

La documentación vigente indica que el top-level es primario y que `canonical_report` no lo sustituye.

Por tanto existen dos representaciones activas del mismo resultado.

Decisión:

- el schema v2 vive en el root;
- `canonical_report` se elimina;
- no se mantiene fallback top-level/canonical;
- inspect y agentes consumen únicamente el root v2.

### Hallazgo AR-02 — Alias dentro de los datos

Ejemplos observados:

```text
first_failure / first_actionable_failure
source / source_kind
driver / baseline
db_name / database
failures / failed_tests
report_contract_version / canonical_report.report_version
runner_contract_version / runner_contract.version
agent_contract=deterministic_v2 / agent_decision.contract_version=1
```

Decisión: `DELETE` de aliases y fallbacks después del cutover.

---

## 8. Reportes suite

### Archivos actuales

```text
.testkit/reports/<suite>_latest.json
.testkit/reports/<suite>_<timestamp>.json
.testkit/reports/<suite>__<scope>_latest.json
.testkit/reports/<suite>__<scope>_<timestamp>.json
```

`ResultWriter` puede escribir además una copia `<suite>_latest.json` como “canonical latest” cuando el nombre scoped es distinto.

| Artifact actual | Tipo | Decisión |
|---|---|---|
| timestamped suite report | `EVIDENCE` | `KEEP` conceptualmente |
| scoped latest report | `POINTER` mezclado con payload | `REMAP` |
| canonical latest copy | duplicado físico | `DELETE` |
| fields top-level legacy | compatibilidad | `DELETE` |

### Destino v2

```text
.testkit/runs/<run_id>/suites/<suite_id>/report.json
.testkit/indexes/latest-suite/<suite_id>.json
```

El primer archivo es evidencia inmutable.

El segundo es un pointer pequeño:

```json
{
  "schema": {
    "name": "testkit.artifact_pointer",
    "version": 2
  },
  "run_id": "...",
  "artifact": ".testkit/runs/.../report.json",
  "sha256": "..."
}
```

Un pointer nunca contiene una copia completa del reporte.

---

## 9. Reportes meta

### Archivos actuales

```text
.testkit/reports/meta_latest.json
.testkit/reports/meta__<target>__<scope>_latest.json
.testkit/reports/meta__<target>__<scope>_<timestamp>.json
```

| Artifact actual | Tipo | Decisión |
|---|---|---|
| timestamped meta report | `EVIDENCE` | `KEEP` conceptualmente |
| `meta_latest.json` con payload completo | `POINTER` mezclado con evidencia | `REMAP` |
| copias scoped/latest | duplicación | `DELETE` |

### Destino v2

```text
.testkit/runs/<run_id>/meta/report.json
.testkit/indexes/latest-run.json
```

El meta report debe listar por referencia los suite reports de la misma corrida e incluir su hash.

No debe copiar suites completas salvo un resumen explícito y versionado.

---

## 10. Índices y manifest de corrida

### `runs_latest.json`

Estado actual:

- mantiene filas resumidas;
- admite formato objeto con `runs` y fallback de lista legacy;
- guarda referencias a latest, timestamped y canonical latest.

Decisión:

- `KEEP` del concepto índice;
- `DELETE` del fallback list;
- `DELETE` de tres referencias equivalentes;
- `REMAP` a referencias inmutables por run id y hash.

Destino:

```text
.testkit/indexes/runs.json
```

### `latest_run.json`

Estado actual:

- se publica solo cuando el report root es run-scoped;
- contiene `run_id`, `target`, `report_root` y paths;
- los loaders caen al root general si no existe o no resuelve.

Decisión:

- `KEEP` como pointer;
- siempre versionado;
- artifact inexistente o inválido no debe activar fallback silencioso;
- el caller debe seleccionar explícitamente `--latest` cuando lo necesite.

---

## 11. Agent decision y execution

### Estado actual

El plan de agente puede aparecer simultáneamente en:

- output de `agent-run`;
- top-level `next_action`;
- top-level `decision_basis`;
- subtree `agent_decision`;
- inspect latest/failure/concurrency/seed-state;
- `agent_run_execute_latest.json`;
- artifact timestamped de ejecución.

`AgentRunArtifact` agrega además:

```text
agent_decision
next_action
decision_basis
decision completo
execution
```

Decisión: `DELETE` de la duplicación.

### Destino v2

```text
.testkit/runs/<run_id>/agent/plan.json
.testkit/runs/<run_id>/agent/executions/<execution_id>.json
```

`plan.json` contiene una única acción con `command_spec` neutral.

El execution artifact referencia el plan por:

```text
run_id
plan_sha256
action_id
```

No vuelve a copiar el plan completo.

### Shape mínimo del plan

```json
{
  "schema": {
    "name": "testkit.agent_plan",
    "version": 2
  },
  "run_id": "...",
  "action": {
    "id": "...",
    "kind": "rerun_single_file",
    "executable": true,
    "command_spec": {
      "argv": [],
      "env": {},
      "cwd_role": "project_root"
    }
  }
}
```

### Shape mínimo de ejecución

```json
{
  "schema": {
    "name": "testkit.agent_execution",
    "version": 2
  },
  "run_id": "...",
  "action_id": "...",
  "plan_sha256": "...",
  "executed": true,
  "exit": {
    "code": 0,
    "name": "OK"
  }
}
```

---

## 12. Inspect

Inspect es una vista derivada, no una nueva fuente de verdad.

Actualmente cada vista repite:

- run context;
- failure;
- agent decision;
- next action;
- decision basis;
- warnings;
- artifacts.

Además declara `inspect_contract=agent_decision_v1`.

Decisión:

- inspect usa schema propio `testkit.inspect_result` v2;
- cada vista referencia artifacts fuente;
- no persiste otra copia completa salvo solicitud explícita;
- no se versiona mediante el nombre de agent decision;
- no incluye campos específicos de Tarifa.

---

## 13. History

Archivo actual:

```text
.testkit/history/<suite>.json
```

Contiene acumuladores por test, estados recientes, métricas de suite y `suite_metrics_contract_version=1`.

Decisión:

- `KEEP` como estado interno para fragility y delta;
- `INTERNAL` para automatización externa;
- versionar el root completo;
- escritura atómica obligatoria;
- un history corrupto no puede convertirse silenciosamente en history vacío;
- los reportes deben referenciar el history usado mediante hash o versión.

---

## 14. Coverage

Artefactos actuales:

```text
.testkit/coverage/<suite_id>/coverage.json
.testkit/coverage/<suite_id>/lcov.info
.testkit/coverage/<suite_id>/coverage_diagnostics.json
.testkit/coverage/<suite_id>/coverage_report.md
.testkit/coverage/<suite_id>/coverage_meta.json
```

Fallbacks actuales:

```text
test/coverage/php_back
test/coverage/php_front
test/coverage/python_back
```

| Artifact | Tipo | Decisión |
|---|---|---|
| `coverage.json` | `EVIDENCE` | `KEEP` |
| `lcov.info` | `INTEROP` | `KEEP` |
| `coverage_diagnostics.json` | `DIAGNOSTIC` | `KEEP` |
| `coverage_report.md` | `HUMAN` | `KEEP` |
| `coverage_meta.json` | metadata | `REMAP` a schema root común |
| paths `test/coverage/*` | legacy | `DELETE` |
| `TEST_COVERAGE_DIR` | alias | `DELETE` |

Destino:

```text
.testkit/runs/<run_id>/suites/<suite_id>/coverage/
```

Todos los artifacts de coverage deben quedar vinculados al mismo `run_id` y report suite mediante hashes.

---

## 15. SQL profiling y observability

Artifacts observados:

```text
run-manifest.json
suite-report.json
evidence.json
profile-stability.json
mysql_profile.json
mysql_policy.json
mysql_comparison.json
mysql_gate.json
mysql_gate.junit.xml
mysql_gate.sarif
mysql_gate_summary.md
mysql_baseline_approval.json
exit-code.txt
summary.md
logs sanitizados
```

### Decisión

Los schemas especializados pueden permanecer porque representan dominios técnicos distintos:

```text
mysql-query-profile-report-v2
mysql-query-gate-report-v1
mysql-query-gate-evidence-v1
mysql-query-baseline-approval-report-v1
SARIF 2.1.0
JUnit XML
```

Pero deben cumplir:

1. ubicación bajo el run root común;
2. manifest raíz con schema `testkit.artifact_manifest` v2;
3. hash SHA-256 por artifact;
4. referencia desde suite report;
5. salida code transportada por la tabla común;
6. ausencia de secrets y credenciales temporales;
7. sin `suite-report.json` paralelo que luego se copie a otro suite report.

El `suite-report.json` específico de SQL Observability debe integrarse en el productor común de reportes o convertirse en input interno, no coexistir como segunda representación pública.

---

## 16. Config contract

Actualmente `config-schema --json` publica:

```text
schema_version=5
support_contract_version=1
commands
targets
support_matrix
environment
notes
```

Decisión:

Destino único:

```text
testkit contract --json
schema.name = testkit.contract
schema.version = 2
```

No debe publicar listas mantenidas manualmente.

El payload se genera desde el futuro registro contractual único.

---

## 17. Manifest raíz de artifacts v2

Cada corrida debe producir:

```text
.testkit/runs/<run_id>/manifest.json
```

Shape mínimo:

```json
{
  "schema": {
    "name": "testkit.artifact_manifest",
    "version": 2
  },
  "run_id": "...",
  "generated_at": "...",
  "producer": {
    "name": "testkit",
    "commit": "..."
  },
  "operation": "run",
  "artifacts": [
    {
      "kind": "suite_report",
      "suite_id": "back_php",
      "path": "suites/back_php/report.json",
      "sha256": "...",
      "schema": {
        "name": "testkit.suite_report",
        "version": 2
      }
    }
  ]
}
```

### Invariantes

1. Paths del manifest son relativos al run root.
2. No se aceptan paths absolutos como identidad contractual.
3. Cada artifact JSON declara `schema.name` y `schema.version`.
4. Cada artifact inmutable tiene SHA-256.
5. Mutable pointers no se usan como evidencia.
6. Un artifact requerido ausente invalida la corrida.
7. JSON inválido no se interpreta como objeto vacío.
8. No existe fallback a formatos legacy.
9. Un schema desconocido falla explícitamente.
10. Los artifacts humanos nunca son la única evidencia de una decisión.

---

## 18. Mapa productor → consumidor

| Artifact | Productor actual | Consumidor actual | Destino |
|---|---|---|---|
| suite report | `ResultWriter` | MetaRunner, inspect, agent, report UI | root v2 único |
| meta report | `ResultWriter` | inspect, agent, Definition of Done | root v2 único |
| runs index | `RunIndexWriter` | tooling e inspección | index v2 |
| latest run | `ResultWriter` | agent loaders e inspect | pointer v2 |
| canonical report | `CanonicalReport` | agent, inspect, validators | `DELETE`; root v2 |
| agent decision | `AgentDecisionBuilder` | CLI, inspect, executor | `agent/plan.json` |
| agent execution | `AgentRunArtifact` | inspect | execution artifact por id |
| history | `HistoryRepository` | fragility/delta | interno versionado |
| coverage | suites/coverage writers | report UI y consumers externos | run-scoped |
| SQL gate | query gate | SQL obs, CI, SARIF/JUnit consumers | schema especializado bajo manifest |
| config schema | `ConfigSchema` | humanos/agentes | `testkit.contract` v2 |

---

## 19. Riesgos

### R-01 — Cortar fallbacks rompe consumidores silenciosos

Consumidores externos pueden leer:

- `canonical_report`;
- `failed_tests`;
- `*_latest.json`;
- `runs_latest.json` como lista;
- paths legacy de coverage.

Mitigación: completar inventario externo y adaptar consumidores antes del cutover. No agregar compatibilidad al runtime nuevo.

### R-02 — Latest no es evidencia inmutable

Una automatización puede leer `*_latest.json` después de que otra corrida lo reemplace.

Mitigación: toda automatización trabaja con `run_id`, manifest y hash.

### R-03 — Collapse de exit codes destruye causalidad

Convertir cualquier fallo a `1` impide diferenciar policy block, timeout, contention y error operacional.

Mitigación: transporte exacto de la tabla común.

### R-04 — Demasiados schemas sin namespace

Números como `1`, `2` o `5` no indican qué contrato versionan.

Mitigación: todo JSON usa `schema.name` más `schema.version`.

### R-05 — History corrupto o artifact ausente puede parecer vacío

Varios loaders devuelven `[]` o `null` ante archivo ausente, vacío o JSON inválido.

Mitigación: separar `not_found`, `empty`, `invalid_json`, `schema_mismatch` y `io_error`.

---

## 20. Dependencias para implementación

Orden obligatorio:

```text
1. registro contractual único
2. enum común de exit codes
3. schemas JSON v2
4. manifest y run root inmutable
5. producers suite/meta
6. agent plan/execution
7. inspect y Definition of Done
8. coverage y profiling
9. wrappers Bash/PowerShell
10. consumidores externos
11. eliminación de fallbacks
```

No debe implementarse primero la nueva ruta de archivos dejando producers y loaders antiguos activos en paralelo.

---

## 21. Criterio PASS de Fase 0.3

- cada código observado tiene productor, significado y decisión;
- la colisión del código `2` está documentada;
- el passthrough especial de SQL Observability está documentado;
- existe tabla objetivo única;
- cada artifact principal tiene tipo y decisión;
- latest, evidence, index e history están diferenciados;
- las versiones duplicadas están inventariadas;
- `canonical_report` y fallbacks legacy tienen decisión explícita;
- existe manifest raíz objetivo;
- no se modificó runtime.

## 22. Criterio FAIL

- tratar `*_latest.json` como evidencia inmutable;
- conservar dos shapes equivalentes;
- mantener aliases de campos;
- usar un mismo código para request inválido y selección vacía;
- colapsar todos los fallos a `1`;
- mantener excepciones por nombre de suite;
- declarar un schema mediante un número sin nombre;
- asumir paridad de códigos Windows/Linux sin ejecución;
- modificar runtime en este commit documental.

---

## 23. Validación reproducible pendiente

Comandos para un checkout limpio:

```bash
git status --short
git rev-parse HEAD

git grep -nE 'exit\([0-9]+\)|return [0-9]+;|EXIT_[A-Z_]+' -- \
  '*.php' '*.sh' '*.ps1' '*.mjs'

git grep -nE 'report_contract_version|runner_contract_version|schema_version|contract_version|artifact_contract_version|report_version|inspect_contract'

git grep -nE '_latest\.json|runs_latest\.json|latest_run\.json|canonical_report|failed_tests|TEST_COVERAGE_DIR|test/coverage'

git diff --check main...agent/testkit-contract-normalization
git diff --name-status main...agent/testkit-contract-normalization
git diff --stat main...agent/testkit-contract-normalization
```

Resultado esperado para esta rama documental:

```text
solo Markdown agregado bajo docs/pendientes/normalizacion-contratos/
sin código
sin workflows
sin deletes
sin renames
sin gitlinks
```

## 24. No verificado

- transporte real de todos los códigos mediante Docker Compose;
- códigos devueltos por PowerShell en Windows;
- comportamiento de Docker Desktop;
- todos los scripts auxiliares de profiling y cleanup;
- consumers privados o no indexados;
- parsers externos de `canonical_report`;
- artifacts reales producidos en una corrida completa del baseline;
- atomicidad bajo fallos de filesystem;
- colisiones de timestamp de reportes;
- cleanup y retención sobre run roots v2;
- compatibilidad de JUnit/SARIF después de mover paths.

## 25. Rollback

Revertir únicamente el commit que agrega este documento o borrar la rama antes del merge.

No hay rollback operacional porque no se modificaron runtime, configuración, wrappers, tests, workflows ni consumidores externos.

## 26. Siguiente pendiente

Fase 0.4 debe completar:

1. matriz reproducible Bash/PowerShell;
2. diferencias de build, env, project root, compose y rewrite;
3. ADR de cutover sin aliases ni compatibilidad;
4. resolución final de elementos `VERIFY` de Fase 0;
5. comandos de baseline que deberán ejecutarse antes del primer cambio de runtime.
