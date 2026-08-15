# Pendiente externo — Integraciones y cutover de consumidores

## Estado

Activo como plan de integración externa.

La única fase ejecutable desde este punto sin modificar consumidores es **E1 — inventario read-only**. E2–E7 requieren evidencia adicional, autorización explícita sobre cada repositorio afectado o dependencias internas cerradas.

## Baseline de esta revisión

```text
testKit main: ed1a35b85f87cf124495941211d39c6e3b9b6906
Fecha: 2026-08-15
```

## Regla de ownership

Este documento registra trabajo que exige cambios o evidencia fuera de `lucasborges2001/testKit`.

Ningún punto autoriza modificar otro repositorio. Cada consumidor requiere una orden explícita, baseline propio, rama/flujo autorizado y validación propia.

## Estado resumido

| Fase | Estado | Dependencia principal |
|---|---|---|
| E1 — Inventario actualizado de consumidores | `READY_READ_ONLY` | Ninguna modificación externa. |
| E2 — Integración mediante Base | `DEPENDENT_CONDITIONAL` | E1 debe confirmar que `Base` participa realmente en la distribución/consumo. |
| E3 — Integración en Pruebas | `DEPENDENT_CONDITIONAL` | E1 debe confirmar contratos y archivos concretos a adaptar. |
| E4 — Migración de otros consumidores | `DEPENDENT` | E1 debe identificar repositorios, owners y superficie legacy real. |
| E5 — Runtime Windows real | `VERIFICATION_PENDING` | Requiere Windows real o runner equivalente y runtime disponible. |
| E6 — Cutover coordinado | `BLOCKED` | I3–I8 necesarios cerrados, consumidores migrados y evidencia individual. |
| E7 — Release/tag contractual | `BLOCKED` | E6 cerrado y autorización explícita de publicación. |

## Principio de secuencia

E1 debe ocurrir **antes** de eliminar aliases o bridges internos con potencial consumo externo. Esto evita usar compatibilidad indefinida, pero también evita romper consumidores desconocidos.

E1 no autoriza cambios. Su salida es un inventario verificable que alimenta I3/I4/I5 y decide si E2, E3 o E4 aplican realmente.

## E1 — Inventario actualizado de consumidores

### Estado

`READY_READ_ONLY`.

### Objetivo

Identificar repositorios, workflows, scripts y documentación ejecutable que invoquen la superficie anterior o dependan de compatibilidad interna de `testKit`.

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
aliases de stack: postgres, postgresql, influxdb
```

La búsqueda debe incluir consumidores directos y rutas de distribución/pinning conocidas, no solo código PHP.

### Evidencia requerida por consumidor

- repositorio;
- rama y SHA auditado;
- archivo y línea o fragmento identificable;
- comando, variable, path o contrato utilizado;
- contrato canónico de reemplazo;
- owner del cambio;
- pruebas o smokes disponibles;
- relación con `Base`, `Pruebas` u otro host si existe;
- clasificación `MIGRAR | NO_APLICA | DECISION_PENDIENTE`.

### Criterio PASS

- cada uso legacy encontrado tiene owner y destino;
- se distingue uso activo de documentación histórica;
- no queda ningún consumidor conocido bajo descripciones vagas como “revisar después”;
- E2/E3/E4 quedan activados o descartados por evidencia, no por suposición.

### Salida esperada

Un inventario reproducible. E1 no debe modificar repositorios consumidores.

## E2 — Integración mediante Base

### Estado

`DEPENDENT_CONDITIONAL`.

No asumir que `Base` distribuye o debe distribuir `testKit`. E1 debe verificar primero el mecanismo real.

### Si aplica

- fijar un SHA explícito de `testKit`;
- actualizar distribución o contratos públicos de `Base` únicamente si su arquitectura lo requiere;
- no copiar internals de `testKit` dentro de `Base`;
- documentar SHA anterior, SHA nuevo y rollback;
- separar cambio del submódulo y actualización de gitlink/pin cuando corresponda.

### Criterio PASS

- el mecanismo de consumo está verificado;
- `Base` no depende de aliases eliminados;
- pruebas contractuales correspondientes pasan;
- rollback del pin/gitlink está documentado.

## E3 — Integración en Pruebas

### Estado

`DEPENDENT_CONDITIONAL`.

### Si E1 confirma consumo

- adaptar comandos del host a selectores tipados;
- actualizar adapters, smokes y documentación de integración;
- no mover lógica de dominio hacia `testKit`;
- separar actualización de submódulo/gitlink de cambios de host;
- validar que los submódulos funcionales sigan siendo propietarios de sus tests.

### Criterio PASS

- `Pruebas` consume un SHA fijado;
- no utiliza aliases eliminados;
- smokes focalizados pasan;
- rollback del gitlink está documentado;
- fallos de otros submódulos se distinguen del cambio de `testKit`.

## E4 — Migración de otros repositorios consumidores

### Estado

`DEPENDENT` de E1.

Cada repositorio confirmado debe migrarse de forma independiente.

### Cambios típicos

```text
php runTest.php <target>
-> php runTest.php --suite|--group|--category <nombre>

TEST_MATCH=<ruta>
-> --test <ruta>
```

Los reemplazos concretos deben derivarse del contrato real auditado; no aplicar transformaciones mecánicas a ciegas.

### Criterio PASS por repositorio

- baseline y owner registrados;
- no quedan targets posicionales activos;
- no quedan aliases eliminados activos;
- la CI/smoke propia disponible pasa o su bloqueo queda clasificado;
- documentación y scripts coinciden;
- el cambio no requiere reintroducir compatibilidad dentro de `testKit`.

## E5 — Runtime Windows real

### Estado

`VERIFICATION_PENDING`.

No depende de que la CI remota esté disponible si existe un host Windows autorizado, pero no puede declararse PASS mediante parseo estático o self-tests solamente.

### Objetivo

Obtener evidencia de PowerShell, Docker Desktop/WSL2 cuando corresponda, mounts, quoting, paths, stores y códigos de salida en Windows.

### Criterio PASS

- ejecución en host Windows real o runner equivalente documentado;
- baseline, versiones y configuración relevante registrados;
- smokes reproducibles;
- artifacts conservados;
- resultado comparable con Linux;
- no se declara paridad por parseo estático solamente.

La terminación de procesos/timeout nativo sigue una deuda separada en `../processrunner-timeout-windows.md`.

## E6 — Cutover coordinado

### Estado

`BLOCKED`.

### Precondiciones

- E1 cerrado;
- I3–I8 requeridos implementados o convertidos formalmente a verificaciones de entorno;
- consumidores conocidos migrados contra SHA fijo;
- contratos externos ejecutados;
- plan de rollback verificable por consumidor.

### Secuencia

1. elegir SHA candidato de `testKit`;
2. adaptar consumidores uno por uno;
3. validar CI y smokes de cada consumidor;
4. actualizar pins o gitlinks mediante commits separados;
5. integrar el corte sin aliases;
6. registrar evidencia final por repositorio.

### Criterio PASS

Ningún consumidor conocido necesita compatibilidad legacy para operar contra el SHA candidato.

## E7 — Release o tag contractual

### Estado

`BLOCKED` por E6 y por autorización explícita de publicación.

Solo después del cutover:

- decidir si corresponde tag o release;
- documentar último SHA del contrato anterior;
- documentar primer SHA del contrato nuevo;
- publicar notas de ruptura sin prometer compatibilidad inexistente.

## Rollback externo

- volver cada consumidor a su SHA anterior;
- revertir pins o gitlinks de forma explícita;
- no reintroducir aliases en `testKit` como rollback por defecto;
- no mezclar rollback de un consumidor con cambios en otros repositorios.

## Acciones excluidas desde este pendiente

- modificar `Base`, `Pruebas` o cualquier consumidor sin autorización;
- hacer merge automático;
- actualizar gitlinks junto con cambios internos de submódulos;
- declarar consumidores migrados sin evidencia suficiente;
- borrar ramas o documentación;
- publicar releases o tags.

## Criterio de cierre

Este pendiente se cierra únicamente cuando:

1. E1 identifica o descarta todos los consumidores conocidos;
2. cada consumidor aplicable tiene evidencia individual de migración o una decisión explícita de no consumo;
3. el cutover puede sostenerse sin aliases legacy en `testKit`;
4. cualquier verificación bloqueada queda fuera del backlog de implementación y registrada en `docs/verificaciones/`.
