# Fase 0.4 — Paridad de wrappers y ADR de cutover

## Estado

Fase 0 completada documentalmente.

Runtime no modificado.

Este documento cierra el inventario contractual iniciado en:

- `README.md`;
- `fase-0-1-inventario-contrato-publico.md`;
- `fase-0-2-inventario-consumidores.md`;
- `fase-0-3-codigos-salida-y-artefactos.md`.

La Fase 1 puede comenzar únicamente mediante un commit separado.

## Baseline

```text
Repositorio: lucasborges2001/testKit
Rama base: main
Commit base: b5b09284c69728dfa93266300f405e3e57157684
Rama de trabajo: agent/testkit-contract-normalization
Commit Fase 0.1: 25cebc594a49c366d6cd65829c9fd4b240fbcf53
Commit Fase 0.2: 70f6b6e399bb412092d2a63992085167016446af
Commit Fase 0.3: 52ff9b1e9222bcea9871178390f87507bc24c432
Fecha de inspección: 2026-07-28
```

## Objetivo

Cerrar la Fase 0 mediante:

1. matriz reproducible Bash/PowerShell;
2. clasificación de diferencias observadas;
3. decisiones de paridad objetivo;
4. resolución de elementos `VERIFY` que bloqueaban el diseño;
5. ADR del corte incompatible sin aliases ni modo dual;
6. baseline de validación previo al primer cambio de runtime.

---

# Parte A — Matriz Bash/PowerShell observada

## 1. Entry points

| Superficie | Bash | PowerShell | Estado |
|---|---|---|---|
| wrapper público | `bin/testkit` | `bin/testkit.ps1` | ambos existen |
| inicialización env | `lib/bash/env.sh` | `lib/powershell/Env.ps1` | semántica distinta |
| resolución stack | `lib/bash/stack.sh` | `lib/powershell/Stack.ps1` | aliases equivalentes |
| rewrite runtime | `lib/bash/rewrite.sh` | `lib/powershell/Rewrite.ps1` | no equivalente |
| doctor | `lib/bash/doctor.sh` | `lib/powershell/Doctor.ps1` | paridad no demostrada |
| inspect | Compose/Linux container | Compose/Linux container | forma similar |
| cleanup | Compose/Linux container | Compose/Linux container | forma similar |
| SQL Observability | ejecución PHP host especial | sin ruta especial | divergencia crítica |

Decisión:

- `KEEP` de dos entrypoints de transporte;
- `DELETE` de lógica contractual duplicada dentro de wrappers;
- `REMAP` de ambos wrappers hacia un plan normalizado común.

## 2. Resolución de project root

### Bash observado

Orden efectivo:

```text
TESTKIT_PROJECT_ROOT
-> cwd si contiene test/.env.test o .env.test
-> parent de testKit
```

### PowerShell observado

Orden efectivo:

```text
TESTKIT_PROJECT_ROOT
-> parent de testKit
```

PowerShell no prueba el cwd como proyecto candidato.

### Riesgo

La misma invocación desde un checkout host puede montar proyectos distintos según shell.

### Decisión

Contrato objetivo:

```text
--project-root <absolute path>
```

Reglas:

1. los agentes siempre lo pasan explícitamente;
2. los wrappers no infieren desde cwd;
3. para operación humana puede existir un default documentado único: parent del checkout de testKit;
4. un path inexistente o no directorio devuelve `INVALID_REQUEST`;
5. el path normalizado se publica en el plan y en el artifact.

`TESTKIT_PROJECT_ROOT` se elimina de la superficie pública del runner v2. Puede permanecer internamente durante el proceso hijo, sin ser configuración aceptada por usuarios.

## 3. Resolución del archivo env

### Bash observado

```text
TESTKIT_ENV_FILE válido
-> test/.env.test
-> .env.test
```

Un `TESTKIT_ENV_FILE` explícito inexistente falla la resolución.

### PowerShell observado

```text
TESTKIT_ENV_FILE válido
-> test/.env.test
-> .env.test
```

Un override explícito inexistente cae silenciosamente a los candidatos por defecto.

### Decisión

Contrato objetivo:

```text
--env-file <repo-relative or absolute path>
```

Reglas:

1. un path explícito inexistente devuelve `INVALID_REQUEST`;
2. no existe fallback después de un override explícito;
3. si no se pasa el flag, el único orden permitido es `test/.env.test` y luego `.env.test`;
4. el archivo debe estar dentro de `project_root`;
5. la ruta resuelta se publica en `command_spec` sin contenido ni secretos;
6. Bash y PowerShell usan el mismo resultado normalizado.

`TESTKIT_ENV_FILE` deja de ser una alternativa pública paralela al flag.

## 4. Stack y store

Ambos wrappers aceptan actualmente:

```text
mysql
redis
pg
postgres
postgresql
influx
influxdb
```

Ambos normalizan:

```text
postgres|postgresql -> pg
influxdb -> influx
```

Ambos usan además `TEST_STORE_DRIVER=none` para producir stack vacío y aplican un fallback `mysql,redis`.

### Decisión

Tokens públicos únicos:

```text
mysql
pgsql
redis
influx
none
```

Reglas:

1. `pg`, `postgres` y `postgresql` se eliminan;
2. `influxdb` se elimina;
3. `--stack` es repetible y exacto;
4. `none` no puede combinarse con otro token;
5. el stack no se infiere desde credenciales;
6. el store se declara mediante `--store mysql|pgsql|none`;
7. stack y store son conceptos distintos;
8. no existe fallback implícito `mysql,redis` para ejecución de agentes.

El flag heredado `--pg` se elimina.

## 5. Build policy

### Bash observado

`testkit_rewrite_run_command_args` agrega `--build` a `docker compose run` salvo:

```text
TESTKIT_RUN_BUILD=0
--build ya presente
--no-build ya presente
```

### PowerShell observado

No agrega `--build`.

### Riesgo

Una ejecución puede usar una imagen reconstruida en Linux y una imagen stale en Windows.

### Decisión

Contrato objetivo explícito:

```text
--image-policy build
--image-policy reuse
```

No hay default dependiente del shell.

Para agentes, el default contractual será `reuse`; CI o validaciones de imagen deberán pedir `build` explícitamente.

`TESTKIT_RUN_BUILD` se elimina como contrato público.

## 6. Inyección de wrapper kind

### Bash observado

El rewrite inyecta una vez:

```text
-e TESTKIT_WRAPPER_KIND=bash
```

### PowerShell observado

`Convert-TestkitRunArgs` inyecta:

```text
-e TESTKIT_WRAPPER_KIND=powershell
```

Luego `Invoke-TestkitRuntime` vuelve a inyectar el mismo par al detectar `run`.

### Decisión

`wrapper_kind` es metadata interna del transporte.

Reglas:

1. se agrega exactamente una vez;
2. no forma parte del contrato de usuario;
3. no altera targets, selección, exit codes ni schemas;
4. las pruebas verifican el comando final, no solo el converter aislado.

## 7. Rewrite de paths

### Bash observado

Reescribe variantes relativas y absolutas de:

```text
runTest.php
scripts/report.php
scripts/query_report.php
scripts/inspect.php
```

### PowerShell observado

Reescribe únicamente variantes relativas de:

```text
runTest.php
scripts/report.php
scripts/inspect.php
```

No cubre `scripts/query_report.php` ni las variantes `/workspace/...`.

### Decisión

Los agentes no deben depender de rewrite heurístico.

El registro contractual produce directamente:

```json
{
  "argv": ["php", "/workspace/testkit/runTest.php", "back-php"]
}
```

El wrapper solo transporta ese `argv`.

Los rewrites pueden conservarse temporalmente como implementación interna hasta migrar callers, pero deben eliminarse antes del cutover. No se publican como compatibilidad v2.

## 8. SQL Observability

### Bash observado

Detecta strings que contienen:

```text
runTest.php sql-observability
```

Luego:

- interpreta manualmente algunos `-e`;
- exporta variables en el host;
- ejecuta `php runTest.php sql-observability` fuera de Compose.

### PowerShell observado

No implementa esa detección y usa el camino normal de Docker Compose.

### Riesgo

No existe una ejecución equivalente entre plataformas ni una frontera clara entre host y contenedor.

### Decisión

`sql-observability` deja de ser un special-case de wrapper.

El registro de operaciones debe declarar explícitamente:

```text
runtime_kind=host|container
```

La misma operación usa el mismo `runtime_kind` en Bash y PowerShell.

La recomendación objetivo es `container`, salvo que una caracterización posterior demuestre que la observabilidad requiere acceso host no representable mediante mounts y env explícitos.

Estado de esta elección: `VERIFY_BEFORE_IMPLEMENTATION`, no bloquea el cierre documental de Fase 0 porque la regla arquitectónica ya está fijada: nunca dependerá del shell.

## 9. Inspect y cleanup

Ambos wrappers construyen directamente comandos Docker Compose y no pasan por un único planner.

Decisión:

```text
inspect
cleanup
```

deben ser operaciones registradas y producir el mismo `command_spec` normalizado que `run`.

No deben tener reglas separadas de stack, env, project root ni exit codes.

## 10. Doctor

La matriz vigente declara soporte primario Linux/Windows, pero las pruebas Windows son principalmente estáticas y no existe evidencia suficiente de Docker Desktop real.

Decisión:

Estados permitidos:

```text
static_contract_verified
runtime_verified
unsupported
```

Hasta una ejecución real en Windows con Docker Desktop:

```text
Windows = static_contract_verified
Linux = runtime_verified solo para capacidades efectivamente ejecutadas en CI
```

`doctor --readonly --json` debe existir en ambos wrappers con el mismo schema y sin mutaciones.

---

# Parte B — Plan normalizado común

## 11. Shape objetivo

Todo entrypoint público debe resolver primero un plan neutral:

```json
{
  "schema": {
    "name": "testkit.command_plan",
    "version": 2
  },
  "operation": "run",
  "project_root": "/workspace/project",
  "runtime_kind": "container",
  "image_policy": "reuse",
  "stack": ["mysql", "redis"],
  "store": "mysql",
  "env_file": "test/.env.test",
  "command_spec": {
    "argv": ["php", "/workspace/testkit/runTest.php", "back-php"],
    "env": {
      "TESTKIT_MODE": "agent"
    },
    "cwd_role": "project"
  }
}
```

## 12. Responsabilidades

### Registry/core

Responsable de:

- validar operación;
- resolver suite, group o category;
- resolver stack y store;
- resolver paths lógicos;
- fijar image policy;
- producir `argv`, `env` y `cwd_role`;
- publicar schema y exit contract.

### Wrapper Bash

Responsable únicamente de:

- convertir paths host Linux a mounts;
- invocar Docker o proceso host según `runtime_kind`;
- transportar el código exacto.

### Wrapper PowerShell

Responsable únicamente de:

- convertir paths Windows a mounts;
- invocar Docker Desktop o proceso host según `runtime_kind`;
- transportar el código exacto.

### Prohibido

Los wrappers no pueden:

- agregar targets;
- aceptar aliases;
- elegir stack por inferencia;
- reescribir selección;
- reinterpretar exit codes;
- introducir special-cases por suite;
- producir comandos de agente distintos;
- decidir build de forma diferente.

---

# Parte C — Vectores de paridad

## 13. Golden vectors requeridos

La implementación debe compartir fixtures neutrales, por ejemplo:

```json
{
  "case": "back_php_single_test_mysql",
  "input": {
    "operation": "run",
    "suite": "back-php",
    "tests": ["test/back/auth/login.test.php"],
    "stack": ["mysql"],
    "store": "mysql",
    "image_policy": "reuse"
  },
  "expected_plan": {
    "runtime_kind": "container",
    "command_spec": {
      "argv": [
        "php",
        "/workspace/testkit/runTest.php",
        "back-php",
        "--test",
        "test/back/auth/login.test.php"
      ]
    }
  }
}
```

Casos mínimos:

1. help;
2. doctor readonly JSON;
3. contract JSON;
4. list suite;
5. run suite sin store;
6. run suite MySQL;
7. run suite PostgreSQL;
8. selección exacta repetible;
9. selection file;
10. inspect latest;
11. agent plan;
12. agent execute;
13. invalid suite;
14. invalid env path;
15. no tests;
16. contention;
17. timeout;
18. policy blocked;
19. path con espacios;
20. path con comillas simples y dobles.

Cada vector debe validar:

- plan lógico idéntico;
- diferencias permitidas únicamente en paths host y executable del wrapper;
- mismo `exit.code` y `exit.name`;
- mismo artifact schema;
- una sola inyección de metadata de wrapper.

---

# Parte D — Resolución de elementos VERIFY

## 14. Elementos resueltos por decisión

| Elemento | Resolución |
|---|---|
| entrypoints PHP directos | `INTERNAL`; no son API pública v2 |
| Docker Compose passthrough | `INTERNAL`; no es API principal de agentes |
| project root implícito | `DELETE`; flag explícito para agentes |
| env override con fallback | `DELETE`; override inválido falla |
| aliases de stack | `DELETE` |
| `--pg` | `DELETE` |
| build implícito Bash | `DELETE` |
| double wrapper kind | defecto a corregir; una sola inyección |
| rewrites heurísticos | `DELETE` antes del cutover |
| SQL Observability por shell | `DELETE`; runtime_kind contractual |
| código `2=skip` | `DELETE` |
| `canonical_report` paralelo | `DELETE` |
| latest como evidencia | `DELETE`; latest queda como pointer |
| Windows closed primary | `DELETE` hasta runtime real |
| Tarifa dentro del core | `DELETE` en Fase 1 |

## 15. Elementos que requieren ejecución pero no diseño adicional

Estos puntos quedan `NOT_VERIFIED_RUNTIME`, no `VERIFY` arquitectónico:

- Docker Desktop real en Windows;
- quoting de paths complejos en PowerShell;
- transporte completo de códigos `4..8` en ambos wrappers;
- atomicidad de manifests bajo fallos de filesystem;
- cleanup de run roots inmutables;
- runtime_kind final de SQL Observability;
- consumidores privados o ramas no indexadas.

No bloquean Fase 1 porque Fase 1 solo elimina acoplamiento de dominio y no modifica wrappers ni schemas.

---

# Parte E — ADR

## ADR-001 — Cutover incompatible y coordinado de testKit v2

### Estado

Aceptado documentalmente.

No implementado.

### Contexto

El baseline contiene:

- aliases de targets, stack, store, selección y coverage;
- lógica de dominio Tarifa dentro del core;
- planner y executor con action kinds incompatibles;
- reportes duplicados y fallbacks legacy;
- wrappers Bash/PowerShell con semántica distinta;
- consumidores distribuidos mediante `Base/testkit` y hosts externos.

Mantener API vieja y nueva simultáneamente exigiría:

- adapters;
- aliases;
- schemas dobles;
- resolución ambigua;
- pruebas de compatibilidad;
- una fecha posterior de retiro.

Eso contradice el objetivo de contratos 1 a 1.

### Decisión

Se realizará un corte coordinado sin modo dual.

No se implementarán:

```text
aliases temporales
flags deprecated
schema fallback
field fallback
entrypoints equivalentes
adapter legacy
warning-only deprecations
```

La rama de normalización podrá cambiar incompatiblemente hasta completar las fases internas y externas requeridas.

### Secuencia de cutover

```text
1. completar Fase 1: extraer dominio
2. crear registry único
3. implementar CLI/config estrictos
4. implementar protocolo agente v2
5. implementar schemas y exit codes v2
6. implementar paridad de wrappers
7. adaptar Base contra el SHA candidato
8. adaptar consumidores ejecutables contra Base/TestKit candidato
9. ejecutar contratos externos focalizados
10. mergear testKit v2
11. fijar SHA final en Base
12. actualizar gitlinks de hosts
13. eliminar ramas transitorias después de verificar main
```

### Invariantes de corte

1. ningún consumidor se adapta contra una API solo documentada;
2. testKit candidate debe estar fijado por SHA;
3. Base candidate debe estar fijado por SHA;
4. no se usa `main` móvil como dependencia de prueba;
5. cada repositorio tiene un PR de propósito único;
6. la actualización de un submódulo y del gitlink son cambios separados;
7. no hay merge automático por pasar tests parciales;
8. no se elimina documentación pendiente antes de verificar consumidores;
9. rollback es pin/revert, no reintroducción de aliases;
10. no se toca producción ni bases reales.

### Rollback

Durante desarrollo:

- volver al SHA previo de testKit;
- volver al SHA previo de Base;
- revertir gitlinks de hosts.

Después del cutover:

- revertir el commit/merge de testKit v2;
- restaurar pins anteriores;
- no crear una capa de compatibilidad de emergencia dentro de v2.

### Consecuencias positivas

- superficie única;
- menos branches semánticos;
- agentes consumen schemas estables;
- Bash y PowerShell comparten contrato;
- los fallos no se ocultan por alias o fallback.

### Consecuencias negativas

- requiere coordinación entre repositorios;
- el branch candidate puede permanecer abierto varias fases;
- consumidores no inventariados fallarán de forma explícita;
- no existe migración gradual por alias.

Estas consecuencias se aceptan.

---

# Parte F — Baseline reproducible

## 16. Estado Git

```bash
git status --short
git branch --show-current
git rev-parse HEAD
git rev-parse origin/main
git merge-base origin/main HEAD
git submodule status --recursive || true
```

## 17. Alcance documental acumulado

```bash
git diff --check origin/main...HEAD
git diff --name-status origin/main...HEAD
git diff --stat origin/main...HEAD
```

Resultado esperado al cerrar Fase 0:

```text
solo archivos Markdown agregados bajo docs/pendientes/normalizacion-contratos/
sin código
sin workflows
sin deletes
sin renames
sin gitlinks
```

## 18. Inventario fuente

```bash
git grep -nE 'back-py|back-python|python|php-references|references|TESTKIT_TARGET_|--pg|postgresql|influxdb'
git grep -nE 'TEST_MATCH|TEST_MATCH_LIST|TEST_MATCH_FILE|TEST_SELECTION_MATCH_MODE|TEST_MATCH_LIST_MODE'
git grep -nE 'DB_DRIVER|TEST_DB_DRIVER|TEST_STORE_DRIVER|TEST_DB_DSN|TESTKIT_STACK'
git grep -nE 'canonical_report|failed_tests|first_actionable_failure|first_failure'
git grep -nE 'contract_version|schema_version|report_version|artifact_contract_version|runner_contract_version'
git grep -nE 'TESTKIT_WRAPPER_KIND|TESTKIT_RUN_BUILD|sql-observability|query_report.php' -- bin lib
```

## 19. Sintaxis y self-tests antes de runtime

```bash
find core runners scripts tests -type f -name '*.php' -print0 \
  | xargs -0 -n1 php -l

find bin lib scripts tests -type f -name '*.sh' -print0 \
  | xargs -0 -n1 bash -n

php tests/framework/run.php
```

PowerShell:

```powershell
pwsh -NoProfile -File tests/powershell/run.ps1
```

## 20. Runtime focalizado previo al cutover

No ejecutar hasta que cada script haya sido inspeccionado.

```bash
./bin/testkit doctor --compact
./bin/testkit run --rm testkit php runTest.php back-php --list
./bin/testkit run --rm testkit php runTest.php reference-contract --list
./bin/testkit run --rm testkit php scripts/inspect.php latest --json
```

Estos comandos caracterizan el baseline; no validan todavía el contrato v2.

---

# Parte G — Cierre de Fase 0

## 21. Criterio PASS

- comandos públicos inventariados;
- targets, groups y categories clasificados;
- variables y aliases clasificados;
- action kinds inventariados;
- códigos de salida inventariados y tabla objetivo fijada;
- artifacts y schemas inventariados;
- consumidores internos y externos registrados;
- diferencias Bash/PowerShell registradas;
- plan normalizado objetivo definido;
- ADR de cutover aceptado;
- elementos `VERIFY` arquitectónicos resueltos;
- comandos de baseline definidos;
- runtime no modificado.

Resultado documental: `PASS`.

## 22. Criterio FAIL futuro

La Fase 0 se considerará invalidada si una implementación posterior:

- agrega aliases no inventariados;
- conserva dos nombres para una operación;
- mantiene un schema root y otro subtree equivalente;
- hace depender la semántica del wrapper;
- introduce compatibilidad temporal sin nuevo ADR explícito;
- mueve lógica de dominio adicional al core;
- modifica consumidores externos sin pin de SHA;
- declara Windows runtime verificado solo con parseo estático.

## 23. No verificado

- ejecución local de los comandos de baseline;
- CI de esta rama docs-only;
- Docker real;
- Windows/Docker Desktop real;
- repositorios o ramas no indexados;
- estado de checkouts locales del usuario;
- existencia de variables configuradas en GitHub Environments/Secrets;
- consumidores fuera de la organización inspeccionada.

## 24. Rollback

Revertir únicamente este commit documental o borrar la rama antes del merge.

No existe rollback operativo porque no se modificaron runtime, configuración, wrappers, tests, workflows, gitlinks ni consumidores externos.

## 25. Próxima fase

Fase 1 — extraer el acoplamiento de Tarifa del core.

Primer commit de Fase 1 debe:

1. eliminar `core/php/tarifa/`;
2. retirar `tarifa-contract` del resolver;
3. retirar cargas y ramas específicas de Tarifa;
4. retirar evidencia Tarifa de inspect;
5. retirar tests internos específicos de Tarifa;
6. documentar el cambio requerido en Tarifa/Pruebas sin modificar esos repositorios;
7. agregar una prueba de frontera que impida dominios específicos dentro de `core/php/`.

No debe introducir adapters, aliases ni una suite genérica cuyo único consumidor sea Tarifa.