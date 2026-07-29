# Fase 0.1 — Inventario del contrato público actual

## Estado

Inventario documental completado.

Runtime no modificado.

Las decisiones de este documento son destino contractual para las fases posteriores. No implican que los elementos marcados como `RENAME` o `DELETE` ya hayan sido cambiados.

## Baseline

```text
Repositorio: lucasborges2001/testKit
Rama base: main
Commit base: b5b09284c69728dfa93266300f405e3e57157684
Rama de trabajo: agent/testkit-contract-normalization
Commit documental anterior: ce2e4b5a2685b1087c0ac84e423caad981b1e845
```

## Objetivo

Congelar la superficie contractual observada antes de modificarla y asignar una decisión explícita a cada nombre público relevante.

Este inventario cubre:

- entrypoints y comandos;
- targets, grupos, categorías y suite IDs;
- configuración transversal que altera selección o ejecución;
- driver y stack;
- coverage;
- acciones del modo agente;
- artefactos y versiones JSON;
- códigos de salida;
- wrappers Bash y PowerShell;
- fuentes de verdad duplicadas;
- consumidores conocidos y no verificados.

## Convenciones de decisión

| Decisión | Significado |
|---|---|
| `KEEP` | El nombre y la responsabilidad pueden permanecer como contrato único. |
| `RENAME` | La capacidad permanece, pero debe exponerse con otro nombre o en otra dimensión contractual. |
| `DELETE` | El nombre, alias, fallback o responsabilidad no debe existir después del cutover. |
| `INTERNAL` | Puede permanecer como detalle de implementación, pero no como API para proyectos o agentes. |
| `VERIFY` | No existe evidencia suficiente para decidir sin inspeccionar consumidores o ejecución real. |

Reglas:

1. `RENAME` no autoriza convivencia temporal dentro del runtime final.
2. El cutover reemplaza el contrato anterior; no mantiene alias.
3. Un valor marcado `INTERNAL` no puede aparecer como recomendación primaria en documentación para consumidores.
4. `VERIFY` exige evidencia concreta y no puede sobrevivir al cierre de Fase 0.

---

## 1. Entry points y comandos

### 1.1 Entry points físicos

| ID | Superficie actual | Tipo | Decisión | Destino |
|---|---|---|---|---|
| EP-01 | `bin/testkit` | wrapper Bash | `KEEP` | Adapter Linux del contrato común. No debe ser dueño de semántica exclusiva. |
| EP-02 | `bin/testkit.ps1` | wrapper PowerShell | `KEEP` | Adapter Windows del mismo contrato común. |
| EP-03 | `runTest.php` | runner PHP directo | `INTERNAL` | Motor interno invocado por el CLI común. Deja de ser entrypoint recomendado para agentes. |
| EP-04 | `scripts/inspect.php` | inspector PHP directo | `INTERNAL` | Implementación interna del verbo público `inspect`. |
| EP-05 | comandos PHP de reporting/agente | scripts internos | `INTERNAL` | Deben quedar detrás de verbos públicos versionados. |

### 1.2 Operaciones observadas

| ID | Operación actual | Decisión | Operación objetivo |
|---|---|---|---|
| CMD-01 | `php runTest.php --help` | `RENAME` | `testkit help` generado desde el registro contractual. |
| CMD-02 | `php runTest.php <target>` | `RENAME` | `testkit run --suite <suite>` o `testkit run --group <group>`. |
| CMD-03 | `php runTest.php <target> --list` | `RENAME` | `testkit list --suite <suite> --json`. |
| CMD-04 | `php scripts/inspect.php config-schema` | `RENAME` | `testkit contract`. |
| CMD-05 | `php scripts/inspect.php config-schema --json` | `RENAME` | `testkit contract --json`. |
| CMD-06 | `php scripts/inspect.php latest --run=<id> --json` | `RENAME` | `testkit inspect --run-id <id> --view latest --json`. |
| CMD-07 | `php scripts/inspect.php failure --run=<id> --json` | `RENAME` | `testkit inspect --run-id <id> --view failure --json`. |
| CMD-08 | `php scripts/inspect.php concurrency --run=<id> --json` | `RENAME` | `testkit inspect --run-id <id> --view concurrency --json`. |
| CMD-09 | `php scripts/inspect.php seed-state --run=<id> --json` | `RENAME` | `testkit inspect --run-id <id> --view seed-state --json`. |
| CMD-10 | raw `docker compose` passthrough mediante wrappers | `INTERNAL` | Ruta de operador documentada aparte; no es API primaria para agentes. |
| CMD-11 | strings de shell persistidos como siguiente acción | `DELETE` | `command_spec` neutral con `argv`, `env` y `cwd_role`. |

### Decisión

La API pública objetivo usa verbos explícitos:

```text
testkit doctor
testkit contract
testkit list
testkit run
testkit inspect
testkit agent plan
testkit agent execute
```

Los wrappers pueden conservar passthrough operativo, pero el contrato de máquina no debe depender de sintaxis Docker Compose ni de un shell particular.

---

## 2. Targets, grupos, categorías y suites

## 2.1 Suite IDs internos

| Suite ID | Decisión | Nombre público único |
|---|---|---|
| `back_php` | `KEEP` como interno | `back-php` |
| `back_python` | `KEEP` como interno | `back-python` |
| `front_php` | `KEEP` como interno | `front-php` |
| `front_js` | `KEEP` como interno | `front-js` |
| `infra_php` | `KEEP` como interno | `infra-php` |
| `migration_contract` | `KEEP` como interno | `migration-contract` |
| `reference_contract` | `KEEP` como interno | `reference-contract` |
| `sql_observability` | `KEEP` como interno | `sql-observability` |

Regla: el `suite_id` con underscore es identidad interna y de artefactos; el nombre con guion es el único token CLI público.

## 2.2 Targets que representan una suite

| Target actual | Resolución actual | Decisión | Motivo |
|---|---|---|---|
| `back-php` | `back_php` | `KEEP` | Nombre público canónico. |
| `back-py` | `back_python` | `DELETE` | Alias abreviado. |
| `back-python` | `back_python` | `KEEP` | Nombre público canónico. |
| `python` | `back_python` | `DELETE` | Alias ambiguo respecto del área. |
| `py` | `back_python` | `DELETE` | Alias abreviado. |
| `front-php` | `front_php` | `KEEP` | Nombre público canónico. |
| `front-js` | `front_js` | `KEEP` | Nombre público canónico. |
| `infra-php` | `infra_php` | `KEEP` | Nombre público canónico. |
| `infra` | `infra_php` | `DELETE` | Colisiona con el concepto de grupo/capa. |
| `http` | `infra_php` | `DELETE` | Alias por caso de uso, no por suite. |
| `migration-contract` | `migration_contract` | `KEEP` | Nombre público canónico. |
| `migration` | `migration_contract` | `DELETE` | Alias singular. |
| `migrations` | `migration_contract` | `DELETE` | Alias plural. |
| `reference-contract` | `reference_contract` | `KEEP` | Nombre público canónico. |
| `references` | `reference_contract` | `DELETE` | Alias plural. |
| `php-references` | `reference_contract` | `DELETE` | Alias por lenguaje. |
| `sql-observability` | `sql_observability` | `KEEP` | Suite técnica propia. |
| `tarifa-contract` | `back_php` + mutación de filtros | `DELETE` | Lógica de dominio y target especial dentro de la plataforma. |

## 2.3 Agregados actuales

| Target actual | Resolución actual | Decisión | Destino |
|---|---|---|---|
| `all` | suites principales | `RENAME` | `--group all`. |
| `back` | `back_php`, `back_python` | `RENAME` | `--group back`. |
| `front` | `front_php`, `front_js` | `RENAME` | `--group front`. |
| `public_html` | `front_php`, `front_js` | `DELETE` | Duplica `front` y filtra layout físico del consumidor. |
| `php` | suites PHP | `RENAME` | `--group php`. |
| `js` | `front_js` | `DELETE` | Un grupo de un único elemento no agrega contrato útil. |

Regla: un grupo no puede ser aceptado por `--suite`.

## 2.4 Categorías usadas como targets

| Valor actual | Decisión | Destino |
|---|---|---|
| `smoke` | `RENAME` | `--category smoke` |
| `perf` | `RENAME` | `--category perf` |
| `stress` | `RENAME` | `--category stress` |
| `contract` | `RENAME` | `--category contract` |
| `critical` | `RENAME` | `--category critical` |
| `security` | `RENAME` | `--category security` |
| `slow` | `RENAME` | `--category slow` |

Regla: una categoría filtra una suite o grupo explícito; nunca decide por sí misma qué suites ejecutar.

## 2.5 Override dinámico de targets

| Superficie | Decisión | Motivo |
|---|---|---|
| `TESTKIT_TARGET_<TARGET>` | `DELETE` | Permite redefinir el significado público de un target fuera de la autoridad contractual. |

---

## 3. Selección y filtros

| Variable/flag actual | Decisión | Destino contractual |
|---|---|---|
| `TEST_SCOPE` | `KEEP` | Variable canónica para scope; CLI equivalente `--scope`. |
| `TEST_CATEGORY` | `KEEP` | Variable canónica para categoría; CLI equivalente `--category`. |
| `TEST_MATCH` | `DELETE` | Filtro substring legacy y ambiguo. |
| `TEST_MATCH_LIST` | `RENAME` | Reemplazar por `--test <repo-relative-path>` repetible. |
| `TEST_MATCH_FILE` | `RENAME` | Reemplazar por `--selection-file <path>`. |
| `TEST_MATCH_LIST_MODE` | `DELETE` | La selección pública será exacta. |
| `TEST_SELECTION_MATCH_MODE` | `DELETE` | Alias legacy. |
| `TEST_LIST` | `INTERNAL` | Estado derivado del verbo `list`, no configuración pública. |
| `TEST_REQUIRE_TESTS` | `KEEP` | Invariante explícita para selección vacía. |
| `TEST_RERUN_FAILED_ISOLATED` | `KEEP` | Capacidad única; deberá tener flag CLI equivalente. |
| `TEST_ISOLATED_RERUN_ACTIVE` | `INTERNAL` | Guard de recursión. |

### Invariantes objetivo

1. Las rutas de test son repo-relative y exactas.
2. Path absoluto y traversal con `..` fallan.
3. No existe precedencia entre tres mecanismos equivalentes.
4. Cero tests con `require-tests=true` produce fallo contractual.
5. La selección efectiva queda persistida en JSON.

---

## 4. Ejecución y fail-fast

| Variable actual | Decisión | Observación |
|---|---|---|
| `TEST_FAIL_FAST` | `KEEP` | Política intra-suite. |
| `TEST_META_FAIL_FAST` | `KEEP` | Política del agregado. |
| `TEST_CHILD_FAIL_FAST` | `KEEP` | Política explícita de child suites; revisar nombre en Fase 2. |
| `TEST_JOBS` | `KEEP` | Paralelismo intra-suite. |
| `TEST_DB_STRATEGY` | `KEEP` | Valores públicos finales: `shared|per_worker`. |
| valor `clean` | `DELETE` | Reconocido pero no implementado; debe ser inválido, no una opción publicada. |
| `TEST_BASELINE_MODE` | `KEEP` | Valores `layered|snapshot`. |

`TEST_CHILD_FAIL_FAST` queda marcado para revisión nominal, pero no es alias de otra variable: define una responsabilidad diferente dentro de meta-runs.

---

## 5. Driver de store

## 5.1 Variable canónica

| Superficie | Decisión | Contrato final |
|---|---|---|
| `TEST_STORE_DRIVER` | `KEEP` | Valor obligatorio y exacto cuando la suite requiere resolver store: `mysql|pgsql|none`. |
| `DB_DRIVER` como fuente del driver | `DELETE` | Alias de runtime. |
| `TEST_DB_DRIVER` como fuente del driver | `DELETE` | Segunda fuente equivalente. |
| prefijo de `TEST_DB_DSN` como inferencia | `DELETE` | Un DSN no decide implícitamente el contrato del store. |
| presencia de `PG_DB`/`TEST_PG_DB` como inferencia | `DELETE` | Credenciales visibles no seleccionan motor. |
| fallback automático a `mysql` | `DELETE` | Oculta configuración faltante. |

`TEST_DB_DSN` puede seguir existiendo como dato de conexión en un contrato específico; lo eliminado es su uso para inferir `TEST_STORE_DRIVER`.

## 5.2 Valores

| Valor observado | Decisión |
|---|---|
| `mysql` | `KEEP` |
| `pgsql` | `KEEP` |
| `none` | `KEEP` |
| cualquier prefijo que comience con `pg` | `DELETE` |
| vacío interpretado como `mysql` | `DELETE` |

---

## 6. Stack de servicios

| Variable/valor | Decisión | Destino |
|---|---|---|
| `TESTKIT_STACK` | `KEEP` | Lista explícita de servicios. |
| `mysql` | `KEEP` | Token exacto. |
| `pgsql` | `KEEP` | Token exacto final. |
| `pg` | `RENAME` | Reemplazar por `pgsql` en un único corte. |
| `postgres` | `DELETE` | Alias. |
| `postgresql` | `DELETE` | Alias. |
| `redis` | `KEEP` | Servicio auxiliar. |
| `influx` | `KEEP` | Servicio auxiliar/perfilado. |
| `influxdb` | `DELETE` | Alias. |

Bash, PowerShell, schema, doctor y documentación deben aceptar exactamente los mismos tokens.

---

## 7. Coverage

| Superficie actual | Decisión | Destino |
|---|---|---|
| `TEST_COVERAGE` | `KEEP` | Habilitación explícita. |
| `TEST_COVERAGE_FORMAT` | `KEEP` | `lcov|json|both`. |
| `TEST_COVERAGE_ROOT` | `KEEP` | Único root contractual. |
| `TEST_COVERAGE_DIR` | `DELETE` | Alias legacy. |
| fallback `test/coverage/` | `DELETE` | Path histórico alternativo. |
| `.testkit/coverage/<suite_id>` | `KEEP` | Layout canónico por suite. |
| `TEST_COVERAGE_SOURCE_DIRS` | `KEEP` | Scope de fuentes. |
| `TEST_COVERAGE_EXCLUDE_DIRS` | `KEEP` | Exclusiones. |
| `TEST_COVERAGE_CRITICAL_FILES` | `KEEP` | Archivos críticos. |
| `TEST_COVERAGE_CRITICAL_THRESHOLD` | `KEEP` | Umbral crítico. |
| `TEST_COVERAGE_LOW_THRESHOLD` | `KEEP` | Umbral bajo. |
| `TEST_COVERAGE_SUMMARY_TOP` | `KEEP` | Límite de presentación. |

---

## 8. Configuración de plataforma y wrappers

| Superficie | Decisión | Observación |
|---|---|---|
| `TESTKIT_PROJECT_ROOT` | `KEEP` | Root explícito del proyecto consumidor. |
| inferencia distinta del project root por wrapper | `DELETE` | Bash y PowerShell deben resolver desde la misma entrada. |
| `TESTKIT_ENV_FILE` | `KEEP` | Path explícito; inexistente o externo debe fallar. |
| búsqueda silenciosa con precedencia distinta de env | `DELETE` | Una política común debe resolver el archivo. |
| `TESTKIT_MODE=agent` | `KEEP` | Modo canónico para agentes. |
| inyección de wrapper kind | `INTERNAL` | Debe ocurrir una sola vez y no cambiar semántica. |
| build automático exclusivo de Bash | `DELETE` | La política de build debe ser común y explícita. |
| tratamiento especial de `sql-observability` solo en Bash | `DELETE` | La misma operación debe producir el mismo plan en ambos wrappers. |

### Estado de soporte

| Plataforma | Evidencia actual | Estado contractual objetivo |
|---|---|---|
| Linux/Bash | CI estático y runtime Docker en Ubuntu | `runtime_verified` |
| Windows/PowerShell | Parseo, self-tests y contratos estáticos | `static_contract_verified` |
| Windows + Docker Desktop | No verificado en CI actual | `VERIFY` hasta obtener smoke real |

No se debe publicar Windows como equivalente runtime a Linux hasta contar con evidencia reproducible.

---

## 9. Acciones del modo agente

## 9.1 Acciones producidas por el planner actual

| Action kind | Decisión |
|---|---|
| `inspect_concurrency` | `KEEP` |
| `inspect_latest` | `KEEP` |
| `inspect_seed_state` | `KEEP` |
| `rerun_single_file` | `KEEP` |
| `list_tests` | `KEEP` |
| `no_action` | `RENAME` a `stop` |

## 9.2 Acciones aceptadas por el executor actual

| Action kind | Decisión |
|---|---|
| `stop` | `KEEP` |
| `inspect_concurrency` | `KEEP` |
| `inspect_failure` | `KEEP` |
| `refine_selection` | `DELETE` | Ambiguo; reemplazar por `inspect_latest` o selección explícita. |
| `run_selected_tests` | `KEEP` |
| `rerun_single_file` | `KEEP` |

## 9.3 Enum canónico objetivo

```text
stop
inspect_latest
inspect_failure
inspect_concurrency
inspect_seed_state
list_tests
run_selected_tests
rerun_single_file
```

Cualquier action kind fuera de ese enum debe fallar con error contractual. No puede retornar `executed=false` y exit code `0` por caer en un caso por defecto.

## 9.4 Representación de comando

| Representación actual | Decisión |
|---|---|
| string Bash con `./bin/testkit` | `DELETE` |
| prefijo POSIX `TESTKIT_MODE=agent` | `DELETE` como formato persistido |
| quoting POSIX embebido | `DELETE` como formato persistido |
| `command_spec.argv` | `KEEP` como contrato v2 |
| `command_spec.env` | `KEEP` como contrato v2 |
| `command_spec.cwd_role` | `KEEP` como contrato v2 |
| string `display` | `INTERNAL`/presentación humana |

---

## 10. Artefactos y versiones JSON

| Superficie observada | Estado actual | Decisión |
|---|---|---|
| top-level de reportes suite/meta | contrato histórico principal | `RENAME` hacia schema raíz v2 |
| `canonical_report.report_version=1` | estructura anidada adicional | `DELETE` como duplicación después del corte |
| `agent_decision.contract_version=1` | contrato anidado | `RENAME` hacia schema v2 único |
| `agent_contract=deterministic_v2` | etiqueta paralela de versión | `DELETE` como versión independiente |
| `ConfigSchema.schema_version=5` | versión de configuración | `KEEP` solo si pasa a namespace de schema específico |
| `support_contract_version=1` | versión adicional global | `DELETE` o integrar al schema raíz |
| aliases `source|source_kind` | normalización legacy | `DELETE` |
| aliases `driver|baseline` | normalización legacy | `DELETE` |
| aliases `db_name|database` | normalización legacy | `DELETE` |
| fallback `report_top_level_legacy` | compatibilidad histórica | `DELETE` |

### Schema raíz objetivo

Cada artifact debe identificar explícitamente:

```json
{
  "schema": {
    "name": "testkit.<artifact>",
    "version": 2
  }
}
```

No se permite representar el mismo dato en top-level y dentro de otra estructura canónica.

### Familias de artifacts a cerrar

| Familia | Decisión |
|---|---|
| suite run | `KEEP` con schema v2 |
| meta run | `KEEP` con schema v2 |
| inspect result | `KEEP` con schema v2 |
| agent decision | `KEEP` con schema v2 |
| agent execution | `KEEP` con schema v2 |
| selection manifest | `KEEP` con schema v2 o sección única del run |
| seed state | `KEEP` con nombres únicos |
| coverage summary | `KEEP` con schema propio versionado |
| evidence específica `tarifa_*` | `DELETE` del repositorio |

---

## 11. Códigos de salida

## 11.1 Estado observado

| Código | Uso observado | Decisión |
|---|---|---|
| `0` | éxito, listado y acción no ejecutada | `KEEP` solo para operación válida completada sin fallo |
| `1` | fallo general, suite/meta/operacional | `KEEP` como fallo de tests o ejecución completada con resultado negativo |
| `2` | tratado por meta como no-fallo en child suites; también usado por CLIs auxiliares | `RENAME`/cerrar semántica |
| `3` | target o configuración inválida | `KEEP` como error de uso/configuración, sujeto a tabla v2 |
| `5` | significado especial de `sql-observability` | `DELETE` como semántica específica de una suite |
| `127` | fallo de lanzamiento interno de proceso | `INTERNAL`; mapear a error contractual estable |

## 11.2 Tabla objetivo preliminar

Esta tabla debe cerrarse en Fase 6:

| Código | Significado único propuesto |
|---|---|
| `0` | operación completada y resultado contractual satisfactorio |
| `1` | tests ejecutados con fallos |
| `2` | error operacional/runtime |
| `3` | configuración o argumentos inválidos |
| `4` | evidencia o artifact inválido |
| `5` | operación bloqueada por política/admisión, no por una suite particular |

No se implementa esta tabla en Fase 0. La decisión final requiere inventariar todos los `return`, `exit` y passthrough de procesos.

---

## 12. Fuentes de verdad duplicadas

| Fuente actual | Contenido duplicado | Decisión |
|---|---|---|
| `core/php/suites/TargetResolver.php` | targets y suites | `DELETE` como lista manual; consumir registry |
| `core/php/config/ConfigSchema.php` | targets, env, soporte | `RENAME` como vista generada del registry |
| `core/php/suites/MetaRunner.php` | categorías y mensaje de targets válidos | `DELETE` como lista manual |
| `runners/runTest.php` | ayuda y targets | `DELETE` como lista manual |
| `docs/CONTRATO.md` | nombres y aliases | `RENAME` como documento generado/verificado |
| `docs/USO.md` | comandos y precedencias | `RENAME` como documento generado/verificado |
| `SUPPORT_MATRIX.md` | estados de plataforma/motor | `KEEP`, alimentado por contrato verificable |
| wrappers Bash/PowerShell | reglas de normalización | `DELETE` como semántica duplicada; consumir plan común |

Autoridad objetivo:

```text
ContractRegistry
  -> resolver
  -> parser/CLI
  -> config schema
  -> doctor
  -> help
  -> documentación verificable
  -> tests de consistencia
```

---

## 13. Frontera de dominio

| Elemento actual | Decisión | Destino |
|---|---|---|
| `core/php/tarifa/TarifaContractSupport.php` | `DELETE` | Repositorio Tarifa o soporte del consumidor. |
| target `tarifa-contract` | `DELETE` | Usar suite genérica + selección exacta del consumidor. |
| mutación automática de `TEST_MATCH` para Tarifa | `DELETE` | Selección explícita. |
| `tarifa_evidence` en inspect | `DELETE` | Artifact genérico declarado por el consumidor. |
| tests de reglas Tarifa dentro de testKit | `DELETE` | Suite contractual en Tarifa. |

Regla: testKit puede definir cómo ejecutar, persistir e inspeccionar evidencia; no define campos, moneda, scopes, invariantes ni fixtures de un dominio consumidor.

---

## 14. Self-tests y CI

| Comportamiento actual | Decisión |
|---|---|
| test contractual listado pero ausente produce `SKIP` | `DELETE` |
| manifiesto obligatorio con archivo ausente produce `FAIL` | `KEEP` como objetivo |
| cero self-tests ejecutados puede quedar verde | `DELETE` |
| Windows static checks | `KEEP` |
| runtime Ubuntu/MySQL | `KEEP` |
| runtime Windows Docker Desktop | `VERIFY` |
| comparación registry/help/schema/docs | `KEEP` como gate nuevo |
| detección de aliases nuevos | `KEEP` como gate nuevo |
| detección de dominio dentro del core | `KEEP` como gate nuevo |
| validación JSON Schema | `KEEP` como gate nuevo |
| vectores Bash/PowerShell | `KEEP` como gate nuevo |

---

## 15. Consumidores

## 15.1 Consumidores internos verificados por código

- `MetaRunner` consume `TargetResolver`.
- planner y executor consumen taxonomías de acción distintas.
- reporting consume top-level y estructuras canónicas/legacy.
- wrappers Bash y PowerShell construyen operaciones Docker y env.
- tests framework y PowerShell validan partes de esas superficies.

## 15.2 Consumidores externos

Estado: `VERIFY`.

No se considera verificado qué repositorios consumen:

- aliases de targets;
- `TEST_MATCH` o variables de selección legacy;
- `DB_DRIVER`/`TEST_DB_DRIVER` como selección de store;
- `TEST_COVERAGE_DIR`;
- strings de comando del modo agente;
- estructura top-level o `canonical_report`;
- códigos de salida especiales.

### Evidencia requerida

Para cada consumidor externo:

```text
repositorio
rama/commit
archivo
superficie consumida
comando reproducible
resultado actual
cambio requerido
criterio PASS
```

No se mantendrá compatibilidad por consumidores no identificados. Los consumidores verificados deben actualizarse antes del cutover coordinado.

---

## 16. Contradicciones registradas

| ID | Contradicción | Evidencia principal |
|---|---|---|
| C-01 | runtime acepta targets que `ConfigSchema` omite | `TargetResolver.php` vs `ConfigSchema.php` |
| C-02 | planner produce acciones que executor no admite | `AgentActionPlanner.php` vs `AgentRunExecute.php` |
| C-03 | planner persiste sintaxis Bash para una plataforma declarada Windows/Linux | `AgentActionPlanner.php` |
| C-04 | contrato dice que el dominio pertenece al consumidor, pero core incluye Tarifa | `docs/CONTRATO.md` vs `core/php/tarifa/` |
| C-05 | driver declarado único, pero runtime lo infiere desde múltiples variables | `ConfigSchema.php` vs `StoreRegistry.php` |
| C-06 | schema publica aliases legacy mientras el objetivo exige contrato estricto | `ConfigSchema.php` |
| C-07 | meta considera exit `2` no fallido sin tabla global cerrada | `MetaRunner.php` |
| C-08 | reporte canónico agrega estructura nueva y normaliza nombres legacy | `CanonicalReport.php` |
| C-09 | Windows se declara cerrado sin runtime equivalente verificado | `SUPPORT_MATRIX.md` vs workflow Windows |
| C-10 | self-test ausente puede no romper CI | runners de tests framework/PowerShell |

---

## 17. Dependencias entre cambios futuros

```text
Fase 0 inventario
  -> Fase 1 extracción Tarifa
  -> Fase 2 ContractRegistry
      -> Fase 3 CLI/config estricta
      -> Fase 4 protocolo agente v2
      -> Fase 5 paridad wrappers
      -> Fase 6 reporting/exit codes
      -> Fase 7 gates, consumidores y cutover
```

Restricciones:

1. No eliminar aliases antes de tener registry y tests de contrato.
2. No cambiar planner sin cambiar executor y schema en el mismo objetivo contractual.
3. No declarar paridad Windows por tests estáticos.
4. No cambiar reportes sin fixtures de schema y consumidores identificados.
5. No ejecutar cutover mientras existan elementos `VERIFY` relevantes.

---

## 18. Criterio de aceptación de Fase 0.1

### PASS

- todos los targets observados tienen decisión;
- los aliases relevantes tienen destino explícito;
- planner y executor están inventariados por separado;
- store, stack, selección y coverage identifican su única autoridad futura;
- artefactos y versiones duplicadas están registradas;
- las diferencias de plataforma están declaradas;
- consumidores externos no se inventan y quedan marcados `VERIFY`;
- no se modificó runtime, tests ni CI.

### FAIL

- se interpreta este documento como implementación realizada;
- se agrega compatibilidad nueva;
- se omite `tarifa-contract` por ser reciente;
- se mantiene un alias sin decisión;
- se define Windows runtime como verificado sin ejecución real;
- se elimina una superficie antes de inspeccionar consumidores conocidos.

---

## 19. Validación documental reproducible

Ejecutar sobre la rama:

```bash
git status --short
git branch --show-current
git rev-parse HEAD
git diff main...HEAD --name-status
git diff main...HEAD --check

grep -R "'back-py'\|'python'\|'py'\|'http'\|'migration'\|'migrations'\|'references'\|'php-references'\|'tarifa-contract'" \
  core runners docs tests bin lib

grep -R "TEST_MATCH\|TEST_SELECTION_MATCH_MODE\|TEST_COVERAGE_DIR\|DB_DRIVER\|TEST_DB_DRIVER\|TESTKIT_TARGET_" \
  core runners docs tests bin lib

grep -R "inspect_latest\|inspect_seed_state\|list_tests\|no_action\|inspect_failure\|refine_selection\|run_selected_tests" \
  core tests scripts
```

Resultado esperado en esta fase:

- los nombres legacy siguen apareciendo porque aún no fueron eliminados;
- cada aparición debe poder mapearse a una fila de este inventario;
- el diff de la rama continúa limitado a documentación de pendientes.

---

## 20. Rollback

Este commit es exclusivamente documental.

Rollback:

```bash
git revert <sha-del-commit-de-fase-0-1>
```

No requiere migración de datos, restauración de artifacts ni cambios en consumidores.

---

## 21. No verificado

- ejecución local de la suite framework sobre esta rama;
- comportamiento runtime Docker de Windows;
- inventario completo de consumidores externos;
- todos los códigos de salida de scripts auxiliares;
- todos los artifacts históricos persistidos por versiones anteriores;
- presencia de configuraciones legacy en secretos o workflows de otros repositorios.

Estos puntos deben cerrarse en los siguientes commits de Fase 0 antes de modificar la superficie pública.