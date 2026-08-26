# Pendiente externo — Integraciones y cutover de consumidores

## Estado

Activo como plan de integración externa.

```text
testKit/main: 132ed52e49f530231206e6c4358fe6d3dedf8b19
Fecha: 2026-08-26
```

La única fase ejecutable desde `testKit` sin modificar consumidores es **E1 — inventario read-only**. E2–E7 requieren evidencia adicional y, cuando impliquen cambios, autorización explícita sobre cada repositorio afectado.

## Hechos verificados en testKit

- el contrato público de selección está tipado;
- `testkit.command_spec@1` ya existe: I6 dejó de ser una dependencia de implementación;
- `OperationResultV2` ya existe como I8-A, aunque su verificación de checkout completo sigue abierta;
- I3, I4, I5, I7-A, I8-B e I9 conservan deuda interna;
- esta auditoría no inspeccionó el estado actual de consumidores externos.

## Regla de ownership

Este documento registra trabajo que exige cambios o evidencia fuera de `lucasborges2001/testKit`.

Ningún punto autoriza modificar otro repositorio. Cada consumidor requiere orden explícita, baseline propio, flujo autorizado y validación propia.

## Estado resumido

| Fase | Estado | Dependencia principal |
|---|---|---|
| E1 — Inventario actualizado de consumidores | `READY_READ_ONLY` | Ninguna modificación externa. |
| E2 — Integración mediante Base | `DEPENDENT_CONDITIONAL` | E1 debe demostrar que `Base` participa realmente. |
| E3 — Integración en Pruebas | `DEPENDENT_CONDITIONAL` | E1 debe demostrar qué contrato/adapters deben migrarse. |
| E4 — Otros consumidores | `DEPENDENT` | E1 debe identificar repositorios y superficie legacy real. |
| E5 — Runtime Windows real | `VERIFICATION_PENDING` | Requiere Windows real o runner equivalente. |
| E6 — Cutover coordinado | `BLOCKED` | Deuda interna aplicable cerrada, consumidores migrados y evidencia individual. |
| E7 — Release/tag contractual | `BLOCKED` | E6 cerrado y autorización explícita. |

## E1 — Inventario actualizado de consumidores

### Objetivo

Identificar repositorios, workflows, scripts y documentación ejecutable que dependan de superficie legacy o bridges internos de `testKit`.

### Buscar al menos

```text
TEST_TARGET
TESTKIT_TARGET_*
TEST_MATCH
TEST_MATCH_LIST
TEST_MATCH_FILE
TEST_MATCH_LIST_MODE
TEST_SELECTION_MATCH_MODE
TEST_COVERAGE_DIR
test/coverage/
php runTest.php <target-posicional>
postgres | postgresql | influxdb como aliases de stack
```

También buscar consumidores del nuevo contrato para no migrar dos veces:

```text
testkit.command_spec@1
testkit.operation_result@2
--test
--selection-file
```

### Evidencia requerida por consumidor

- repositorio, rama y SHA;
- archivo y ubicación verificable;
- contrato usado;
- contrato canónico de reemplazo;
- owner;
- pruebas/smokes disponibles;
- relación con `Base`, `Pruebas` u otro host;
- clasificación `MIGRAR | NO_APLICA | DECISION_PENDIENTE`.

### PASS

- cada uso legacy activo tiene owner y destino;
- documentación histórica se distingue de uso ejecutable;
- se identifica qué consumidores ya usan I6/I8-A;
- E2/E3/E4 quedan activados o descartados por evidencia.

## E2 — Integración mediante Base

`DEPENDENT_CONDITIONAL`.

No asumir que `Base` distribuye `testKit`. Si E1 lo confirma:

- fijar SHA explícito;
- reutilizar contratos públicos, no internals;
- separar cambios funcionales de gitlinks/pins;
- documentar SHA anterior, SHA nuevo, pruebas y rollback.

## E3 — Integración en Pruebas

`DEPENDENT_CONDITIONAL`.

Si E1 confirma consumo:

- adaptar el host a selectores/contratos canónicos;
- no mover lógica de dominio a `testKit`;
- separar adapters del host de internals de submódulos;
- validar smokes focalizados y rollback del gitlink si aplica.

## E4 — Otros consumidores

`DEPENDENT` de E1.

Cada repositorio confirmado se migra de forma independiente. No aplicar reemplazos mecánicos sin comprobar el contrato real.

### PASS por consumidor

- baseline y owner registrados;
- no quedan usos legacy activos dentro del alcance migrado;
- scripts y documentación coinciden;
- CI/smokes disponibles pasan o su bloqueo queda clasificado;
- no se reintroduce compatibilidad dentro de `testKit` para evitar la migración.

## E5 — Runtime Windows real

`VERIFICATION_PENDING`.

Requiere evidencia real de PowerShell/paths/mounts/runtime y resultados comparables con Linux. El timeout nativo de `ProcessRunner` sigue un pendiente interno separado en `../processrunner-timeout-windows.md`.

## E6 — Cutover coordinado

`BLOCKED`.

### Precondiciones

- E1 cerrado;
- I3/I4/I5/I7-A/I8-B e I9 cerrados en la parte aplicable al cutover, o convertidos formalmente en verificaciones de entorno;
- verificaciones I6/I8-A sin regresiones que bloqueen el corte;
- consumidores conocidos migrados contra SHA fijo;
- plan de rollback por consumidor.

### PASS

Ningún consumidor conocido necesita compatibilidad legacy para operar contra el SHA candidato.

## E7 — Release/tag contractual

`BLOCKED` por E6 y por autorización explícita de publicación.

## Rollback externo

- volver cada consumidor a su SHA anterior;
- revertir pins/gitlinks explícitamente;
- no reintroducir aliases en `testKit` como rollback por defecto;
- no mezclar rollback de un consumidor con cambios en otros.

## Acciones excluidas

- modificar `Base`, `Pruebas` u otro consumidor desde este pendiente;
- actualizar gitlinks;
- hacer merge, release o tag;
- declarar consumidores migrados sin evidencia;
- declarar Windows PASS sin ejecución real.

## Criterio de cierre

Cerrar sólo cuando E1 identifique o descarte consumidores conocidos, cada consumidor aplicable tenga evidencia individual y el cutover pueda sostenerse sin compatibilidad legacy. Cualquier punto que sólo espere evidencia de entorno debe quedar en `docs/verificaciones/`, no duplicado como implementación pendiente.
