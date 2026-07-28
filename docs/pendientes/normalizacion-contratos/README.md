# Pendiente — Normalización estricta de contratos de testKit

## Estado

Planificación documental.

No implementado.

## Baseline

```text
Repositorio: lucasborges2001/testKit
Rama base: main
Commit base: b5b09284c69728dfa93266300f405e3e57157684
Rama de trabajo: agent/testkit-contract-normalization
```

Este plan parte de una auditoría estática del contrato público, wrappers, configuración, reporting, modo agente, documentación y CI.

La implementación debe avanzar mediante commits pequeños y verificables. Cada commit debe cerrar un único objetivo contractual y no debe introducir aliases, fallbacks de compatibilidad ni períodos duales dentro del runtime.

## Objetivo

Convertir `testKit` en una plataforma de testing con contratos públicos claros, deterministas y consumibles por agentes como Codex y Claude CLI, manteniendo comportamiento equivalente entre Linux/Bash y Windows/PowerShell.

El estado final debe cumplir:

1. una operación pública tiene un solo nombre;
2. una suite tiene un solo target público;
3. una configuración tiene una sola variable propietaria;
4. la configuración inválida falla de forma explícita;
5. el contrato de máquina es JSON versionado, no texto de consola;
6. una acción propuesta por el agente puede ser ejecutada por el mismo contrato;
7. Bash y PowerShell generan la misma operación normalizada;
8. el core no contiene lógica de dominio de proyectos consumidores;
9. documentación, runtime, schema y tests se derivan de una única autoridad;
10. no quedan aliases ni compatibilidad heredada después del cutover.

## Decisión arquitectónica

```text
testKit = plataforma genérica de ejecución, discovery, lifecycle y evidencia
proyecto consumidor = tests, fixtures y reglas de dominio
wrapper Bash = adapter de entrada al contrato común
wrapper PowerShell = adapter de entrada al contrato común
JSON versionado = interfaz primaria para agentes
consola = presentación humana no contractual
```

No se aceptan soluciones que mantengan dos nombres equivalentes, dos variables equivalentes o dos esquemas equivalentes para evitar actualizar consumidores.

## Hallazgos que originan el plan

### H-01 — Planner y executor de agentes desalineados

El planificador produce acciones que el ejecutor no implementa con la misma taxonomía. Una acción puede publicarse como ejecutable y terminar en el caso por defecto sin realizar la operación esperada.

Archivos principales:

- `core/php/reporting/agent/AgentActionPlanner.php`
- `core/php/reporting/AgentRunExecute.php`
- `core/php/reporting/AgentDecisionBuilder.php`
- `tests/framework/test_agent_run_contract.php`
- `tests/framework/test_agent_decision_contract.php`

Prioridad: `P0`.

### H-02 — Comandos de agentes acoplados a Bash

El planificador serializa comandos con `./bin/testkit`, asignaciones POSIX y quoting de shell. Ese texto no es ejecutable 1:1 desde PowerShell.

Prioridad: `P0`.

### H-03 — Lógica de Tarifa dentro del core

El repositorio declara que no contiene reglas de dominio, pero carga soporte específico de Tarifa, expone un target especial y agrega evidencia específica al inspector.

Archivos principales:

- `core/php/tarifa/TarifaContractSupport.php`
- `core/php/bootstrap.php`
- `core/php/suites/TargetResolver.php`
- componentes de `inspect` que exponen `tarifa_evidence`
- tests específicos de Tarifa

Prioridad: `P0`.

### H-04 — Targets con aliases y dimensiones mezcladas

Targets concretos, agregados y categorías comparten la misma superficie. Existen múltiples nombres para suites equivalentes y targets configurables mediante `TESTKIT_TARGET_*`.

Archivos principales:

- `core/php/suites/TargetResolver.php`
- `core/php/suites/MetaRunner.php`
- `runners/runTest.php`
- `core/php/config/ConfigSchema.php`
- `docs/USO.md`
- `docs/CONTRATO.md`

Prioridad: `P0`.

### H-05 — Varias fuentes de verdad

El resolver, la ayuda CLI, `ConfigSchema`, los mensajes de error y la documentación mantienen listas manuales que no coinciden exactamente.

Prioridad: `P0`.

### H-06 — Driver de store inferido por aliases y heurísticas

El driver puede resolverse mediante varias variables, DSN, credenciales visibles y normalización por prefijo. Un agente no puede conocer con certeza qué valor ganó sin reproducir la inferencia interna.

Archivos principales:

- `core/php/store/StoreRegistry.php`
- configuración y doctor asociados
- `.env.test.example`
- `SUPPORT_MATRIX.md`

Prioridad: `P1`.

### H-07 — Stack con sinónimos

`TESTKIT_STACK` acepta variantes como `postgres`, `postgresql` e `influxdb` y las normaliza.

Archivos principales:

- `lib/bash/stack.sh`
- `lib/powershell/Stack.ps1`

Prioridad: `P1`.

### H-08 — Selección de tests superpuesta

Coexisten `TEST_MATCH`, `TEST_MATCH_LIST`, `TEST_MATCH_FILE`, `TEST_MATCH_LIST_MODE` y `TEST_SELECTION_MATCH_MODE`, con precedencia y semántica diferentes.

Prioridad: `P1`.

### H-09 — Coverage con variable y paths legacy

Coexisten `TEST_COVERAGE_ROOT`, `TEST_COVERAGE_DIR`, `.testkit/coverage/` y fallbacks bajo `test/coverage/`.

Prioridad: `P1`.

### H-10 — Wrappers Bash y PowerShell no equivalentes

Difieren en política de build, reescritura de entrypoints, inyección de variables, resolución de proyecto/env y tratamiento de SQL observability.

Archivos principales:

- `bin/testkit`
- `bin/testkit.ps1`
- `lib/bash/*.sh`
- `lib/powershell/*.ps1`
- `tests/powershell/`

Prioridad: `P0`.

### H-11 — Soporte Windows declarado por encima de la evidencia

Windows figura como ruta primaria cerrada, pero CI valida principalmente parseo y contratos estáticos; no existe evidencia runtime equivalente sobre Docker Desktop en Windows.

Prioridad: `P1`.

### H-12 — Self-tests ausentes pueden quedar como SKIP

Los runners de self-tests continúan cuando un test contractual declarado no existe. Una eliminación accidental puede dejar CI verde.

Prioridad: `P1`.

### H-13 — Reportes y versiones duplicados

Existen múltiples números de versión y representaciones duplicadas del mismo resultado entre top-level, `canonical_report` y `agent_decision`.

Prioridad: `P1`.

### H-14 — Documentación repetida y con drift

El mismo contrato aparece en varios documentos y algunas superficies contienen comandos o listas desactualizadas.

Prioridad: `P2`.

## Fases

## Fase 0 — Inventario contractual y baseline reproducible

### Objetivo

Congelar la superficie actual y decidir, para cada elemento, si se conserva, renombra o elimina.

### Entregables

- inventario de comandos públicos;
- inventario de targets y suite IDs;
- inventario de variables de entorno;
- inventario de action kinds de agentes;
- inventario de códigos de salida;
- inventario de artefactos y schemas JSON;
- inventario de wrappers y diferencias por sistema operativo;
- mapa de fuentes de verdad duplicadas;
- mapa de consumidores conocidos dentro y fuera del repositorio;
- decisión `KEEP`, `RENAME` o `DELETE` para cada elemento;
- comandos de baseline reproducibles;
- ADR del corte sin compatibilidad.

### Criterio PASS

- cada nombre público actual tiene una decisión explícita;
- no queda ningún alias sin clasificar;
- las contradicciones runtime/schema/docs están registradas;
- los cambios futuros se pueden dividir sin mezclar responsabilidades;
- no se modifica runtime durante esta fase.

### Criterio FAIL

- se infieren consumidores sin evidencia;
- se empieza a borrar aliases antes de inventariarlos;
- se modifica código junto con el inventario;
- quedan elementos bajo categorías vagas como “revisar después”.

## Fase 1 — Extraer lógica de dominio

### Objetivo

Eliminar del core cualquier contrato específico de Tarifa y devolver esa responsabilidad al proyecto consumidor.

### Criterio PASS

- no quedan namespaces, targets, fixtures, asserts ni artifacts específicos de Tarifa en `testKit`;
- Tarifa puede ejecutarse mediante una suite pública genérica;
- el README coincide con la frontera real del código.

## Fase 2 — Registro contractual único

### Objetivo

Crear una única autoridad para suites, targets, grupos, categorías, capacidades y restricciones.

### Criterio PASS

- resolver, ayuda, schema, doctor y documentación se derivan del mismo registro;
- CI compara todas las superficies;
- una diferencia provoca fallo.

## Fase 3 — CLI y configuración estrictas

### Objetivo

Separar suite, grupo, categoría y selección mediante verbos y flags explícitos.

### Eliminaciones previstas

- aliases de targets;
- `TESTKIT_TARGET_*` como redefinición de contrato público;
- aliases e inferencias de driver;
- aliases de stack;
- `TEST_MATCH` substring;
- `TEST_SELECTION_MATCH_MODE`;
- `TEST_COVERAGE_DIR`;
- fallbacks legacy de coverage;
- `--pg`.

### Criterio PASS

- valor desconocido falla;
- no existe fallback silencioso;
- una operación tiene el mismo significado en cualquier entorno.

## Fase 4 — Protocolo de agentes v2

### Objetivo

Definir una única taxonomía de acciones y un `command_spec` neutral.

### Contrato mínimo esperado

```json
{
  "schema": {
    "name": "testkit.agent_action",
    "version": 2
  },
  "action": {
    "kind": "rerun_single_file",
    "executable": true,
    "command_spec": {
      "argv": [],
      "env": {},
      "cwd_role": "testkit_root"
    }
  }
}
```

### Criterio PASS

Cada acción posible cumple:

```text
planner output
-> schema validation
-> executor admission
-> exact argv/env/cwd
-> exit code
-> artifact persisted
```

## Fase 5 — Paridad Windows/Linux

### Objetivo

Hacer que Bash y PowerShell sean adapters de una operación común.

### Alcance

- build policy;
- resolución de proyecto;
- resolución de env;
- compose files;
- reescritura de entrypoints;
- variables inyectadas;
- SQL observability;
- `doctor --readonly`;
- quoting;
- códigos de salida.

### Criterio PASS

Los mismos vectores de entrada producen el mismo plan normalizado y los mismos resultados contractuales en Bash y PowerShell.

## Fase 6 — Reportes y códigos de salida v2

### Objetivo

Definir un schema raíz único para suite, meta, inspect y agentes.

### Criterio PASS

- todos los artifacts validan contra JSON Schema;
- no existen campos duplicados con semántica equivalente;
- los exit codes tienen una tabla cerrada y estable;
- ningún significado depende de una suite particular.

## Fase 7 — CI, documentación y cutover

### Objetivo

Cerrar gates contra drift y aplicar el corte coordinado sin mantener compatibilidad en runtime.

### Gates requeridos

- test contractual ausente = FAIL;
- drift registry/help/schema/docs = FAIL;
- alias semántico nuevo = FAIL;
- dominio dentro del core = FAIL;
- caracteres de control en documentación = FAIL;
- schema JSON inválido = FAIL;
- vectores de paridad divergentes = FAIL.

### Cutover

1. implementar v2 en la rama;
2. adaptar consumidores conocidos contra un SHA fijo;
3. ejecutar contratos externos;
4. integrar v2 en un único corte;
5. eliminar por completo la superficie anterior;
6. etiquetar el último contrato anterior y el nuevo contrato v2.

### Rollback

- revertir el merge del corte;
- fijar temporalmente consumidores al SHA anterior;
- no reintroducir aliases dentro del runtime.

## Orden de commits recomendado

Cada punto representa un commit independiente salvo evidencia técnica que obligue a dividirlo más.

1. `docs: inventory current public contract`
2. `docs: decide canonical contract names`
3. `refactor: remove Tarifa domain support`
4. `test: enforce domain boundary`
5. `refactor: add canonical contract registry`
6. `test: enforce registry surface consistency`
7. `refactor: normalize suite targets`
8. `refactor: separate groups and categories`
9. `refactor: make store driver explicit`
10. `refactor: normalize stack names`
11. `refactor: replace legacy test selection`
12. `refactor: remove legacy coverage contract`
13. `fix: align agent action planner and executor`
14. `feat: add neutral agent command specification`
15. `test: cover every agent action end to end`
16. `refactor: unify wrapper execution planning`
17. `test: add cross-shell contract vectors`
18. `refactor: publish report schema v2`
19. `test: enforce report and exit-code schemas`
20. `ci: fail on missing contractual tests`
21. `docs: generate contract references from registry`
22. `docs: record consumer cutover evidence`

El orden puede ajustarse cuando Fase 0 pruebe una dependencia distinta. No se debe adelantar una eliminación sin registrar antes sus consumidores.

## Validación por commit

Validación mínima común:

```bash
git status --short
git diff --check
```

Código PHP:

```bash
find . -type f -name '*.php' \
  -not -path './vendor/*' \
  -print0 | xargs -0 -r -n1 php -l

php tests/framework/run.php
```

Bash:

```bash
find bin scripts lib -type f \
  \( -name '*.sh' -o -name 'testkit' \) \
  -print0 | xargs -0 -r -n1 bash -n
```

PowerShell:

```powershell
pwsh -NoProfile -NonInteractive -File tests/powershell/run.ps1
```

Paridad futura:

```bash
bash tests/wrappers/test_contract_vectors.sh
```

```powershell
pwsh -NoProfile -NonInteractive \
  -File tests/powershell/test_contract_vectors.ps1
```

## Riesgos

### R-01 — Consumidores externos no inventariados

Eliminar aliases puede romper proyectos no visibles en el repositorio.

Mitigación: buscar referencias en repositorios consumidores conocidos antes del cutover y registrar evidencia por nombre, archivo y SHA.

### R-02 — Cambio contractual demasiado grande

Un único commit de normalización impediría aislar regresiones.

Mitigación: commits por contrato, tests antes de eliminar y comparaciones exactas contra baseline.

### R-03 — Falsa paridad Windows

Self-tests estáticos no prueban Docker Desktop, mounts NTFS ni comportamiento runtime.

Mitigación: separar `static_contract_verified` de `runtime_verified` hasta contar con evidencia real.

### R-04 — Documentación adelantada al runtime

Una tabla futura puede presentarse como contrato vigente.

Mitigación: cada documento debe indicar estado `planificado`, `implementado` o `verificado` y el commit que lo demuestra.

### R-05 — Compatibilidad reintroducida durante el cutover

La presión por no romper consumidores puede recrear aliases.

Mitigación: actualizar consumidores por SHA y usar rollback de Git, no bifurcaciones permanentes del contrato.

## Acciones excluidas de este pendiente

- modificar proyectos consumidores sin autorización específica;
- mantener aliases temporales dentro del runtime;
- declarar soporte Windows runtime sin smoke real;
- integrar lógica de dominio en el core;
- ejecutar migraciones o bases reales;
- mergear esta rama;
- borrar ramas;
- publicar releases o tags;
- cambiar `main` directamente.

## Criterio de cierre global

Este pendiente se puede cerrar únicamente cuando:

- todas las fases tienen evidencia `PASS`;
- no quedan aliases conocidos;
- runtime, schema, ayuda y documentación son consistentes;
- los agentes consumen JSON versionado y ejecutan el mismo plan publicado;
- Bash y PowerShell pasan los mismos vectores contractuales;
- los consumidores conocidos completaron el cutover;
- el repositorio no contiene lógica de dominio externa;
- CI impide reintroducir las desalineaciones eliminadas.
