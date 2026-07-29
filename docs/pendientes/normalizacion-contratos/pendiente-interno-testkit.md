# Pendiente interno — Contratos restantes de testKit

## Estado

Planificado. No implementado en este corte.

## Ownership

Todo este pendiente pertenece a `lucasborges2001/testKit` y debe poder resolverse sin modificar `Base`, `Pruebas` ni repositorios consumidores.

## Baseline inicial

```text
Rama de referencia: agent/testkit-contract-normalization
Commit de referencia: bef2484529fbfa38e4476399ca96675fbeef6bae
Fecha: 2026-07-28
```

Antes de implementar debe fijarse un baseline nuevo y verificarse el estado de la rama elegida.

## Objetivo

Completar la normalización interna del repositorio sin aliases, fallbacks silenciosos ni contratos duales.

## Fase I1 — Cerrar CI sobre selectores tipados

### Evidencia

El workflow vigente aún contiene invocaciones posicionales o sin selector:

```text
doctor --full migration-contract
php runTest.php --list
php runTest.php all
```

### Cambios internos

- migrar `.github/workflows/ci.yml` a `--suite`, `--group` y `--category`;
- agregar un gate que rechace comandos posicionales en superficies ejecutables internas;
- ejecutar self-tests PHP y PowerShell desde CI;
- conservar artifacts y teardown actuales.

### Criterio PASS

- CI no contiene targets posicionales;
- discovery usa `--group all --list`;
- runtime usa `--group all`;
- doctor usa un selector tipado explícito cuando corresponde;
- Linux y Windows static jobs pasan;
- fallos de baseline se distinguen de fallos introducidos.

## Fase I2 — Store explícito

### Evidencia

`StoreRegistry` y configuración relacionada mantienen inferencias y aliases de driver.

### Objetivo

Una sola variable propietaria y valores canónicos cerrados para el store.

### Criterio PASS

- no se infiere driver por DSN, credenciales o prefijos;
- valor ausente o desconocido falla con código contractual;
- doctor, schema, ejemplo de env y runtime coinciden;
- no existe compatibilidad silenciosa.

## Fase I3 — Stack estricto

### Objetivo

Eliminar sinónimos y shortcuts de stack, incluido `--pg`, conservando una única representación canónica.

### Criterio PASS

- Bash y PowerShell aceptan exactamente los mismos valores;
- `postgres`, `postgresql`, `influxdb` u otros sinónimos no se normalizan;
- valores inválidos fallan antes de invocar Docker;
- tests de paridad cubren todos los valores permitidos y rechazados.

## Fase I4 — Selección de tests única

### Objetivo

Eliminar la superposición entre `TEST_MATCH`, listas, archivos y modos de selección.

### Contrato esperado

- `--test <repo-relative>` repetible para archivos explícitos;
- `--selection-file <repo-relative>` para lotes declarados;
- ninguna selección por substring implícito;
- rutas absolutas y traversal rechazados.

### Criterio PASS

- `TEST_MATCH`, `TEST_MATCH_LIST`, `TEST_MATCH_FILE`, `TEST_MATCH_LIST_MODE` y `TEST_SELECTION_MATCH_MODE` dejan de ser entradas públicas;
- CLI, UI, reporting y rerun usan el mismo modelo;
- selección vacía o contradictoria falla explícitamente.

## Fase I5 — Coverage único

### Objetivo

Definir una única variable de activación, un único formato y una única raíz de artifacts.

### Criterio PASS

- `TEST_COVERAGE_DIR` y paths legacy dejan de ser entradas;
- no existen fallbacks bajo `test/coverage/`;
- reporting publica una sola ruta canónica;
- Linux y PowerShell generan el mismo plan de coverage.

## Fase I6 — Protocolo de agentes v2

### Objetivo

Sustituir comandos shell serializados como contrato primario por un `command_spec` neutral y versionado.

### Criterio PASS

Cada acción cumple:

```text
planner
-> schema validation
-> executor admission
-> argv/env/cwd exactos
-> exit code
-> artifact persistido
```

No se considera PASS si el agente solo publica texto de consola ejecutable en Bash.

## Fase I7 — Paridad de wrappers

### Objetivo

Hacer que Bash y PowerShell sean adapters del mismo plan normalizado.

### Criterio PASS

- mismos vectores de entrada;
- mismo selector, env, compose files y entrypoint;
- mismo código de salida contractual;
- divergencia en vectores provoca fallo;
- soporte runtime Windows se declara solo con smoke real.

## Fase I8 — Reportes y códigos de salida v2

### Objetivo

Unificar suite, meta, inspect y agente bajo schemas versionados sin duplicación semántica.

### Criterio PASS

- JSON Schema validado en CI;
- tabla cerrada de exit codes;
- campos duplicados eliminados;
- ningún significado depende de una suite de dominio;
- consola queda como presentación no contractual.

## Fase I9 — Documentación generada y gates finales

### Objetivo

Eliminar drift entre registro, ayuda, schema, doctor y documentación.

### Gates

- test contractual ausente = FAIL;
- alias semántico nuevo = FAIL;
- dominio externo en core = FAIL;
- documento generado desactualizado = FAIL;
- schema inválido = FAIL;
- vector Bash/PowerShell divergente = FAIL.

## Orden recomendado

```text
I1 CI tipada
I2 store
I3 stack
I4 selección
I5 coverage
I6 agentes
I7 wrappers
I8 reportes
I9 gates/documentación
```

No adelantar I6-I9 para ocultar aliases todavía activos en I2-I5.

## Validación mínima por fase

```bash
git status --short
git diff --check
find . -type f -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -r -n1 php -l
php tests/framework/run.php
find bin scripts lib -type f \( -name '*.sh' -o -name 'testkit' \) -print0 | xargs -0 -r -n1 bash -n
```

```powershell
pwsh -NoProfile -NonInteractive -File tests/powershell/run.ps1
```

## Acciones excluidas

- modificar consumidores externos;
- actualizar gitlinks en `Base` o `Pruebas`;
- crear aliases temporales;
- hacer merge, release o tag sin autorización;
- declarar soporte runtime no probado.
