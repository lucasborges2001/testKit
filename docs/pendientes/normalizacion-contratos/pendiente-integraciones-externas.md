# Pendiente externo — Integraciones y cutover de consumidores

## Estado

Planificado. No autorizado ni ejecutado desde `testKit`.

## Regla de ownership

Este documento registra trabajo que exige cambios o evidencia fuera de `lucasborges2001/testKit`.

Ningún punto de este pendiente autoriza modificar otro repositorio. Cada consumidor requiere una orden explícita, baseline propio, rama propia y validación propia.

## Dependencias previas

Antes de iniciar cualquier integración externa:

- las fases internas necesarias deben estar implementadas y verificadas;
- debe existir un SHA estable de `testKit` para consumir;
- el contrato público debe estar documentado sin compatibilidad dual;
- CI de `testKit` debe estar verde o sus fallos de baseline deben estar clasificados.

## E1 — Inventario actualizado de consumidores

### Objetivo

Identificar repositorios y scripts que invoquen la superficie anterior.

### Evidencia requerida por consumidor

- repositorio;
- rama y SHA auditado;
- archivo y línea;
- comando o variable utilizada;
- contrato canónico de reemplazo;
- owner del cambio;
- pruebas disponibles.

### Criterio PASS

No queda ningún consumidor conocido bajo descripciones vagas como “revisar después”.

## E2 — Integración mediante Base

### Alcance esperado

- fijar un SHA explícito de `testKit`;
- actualizar distribución o contratos públicos de `Base` únicamente si su arquitectura lo requiere;
- no copiar internals de `testKit` dentro de `Base`;
- documentar SHA anterior, SHA nuevo y rollback.

### No verificado

No se verificó en este corte que `Base` distribuya actualmente `testKit` ni qué mecanismo exacto utiliza.

## E3 — Integración en Pruebas

### Alcance esperado

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

## E4 — Migración de repositorios consumidores

Cada repositorio debe migrarse de forma independiente.

### Cambios típicos

```text
php runTest.php <target>
-> php runTest.php --suite|--group|--category <nombre>

TEST_MATCH=<ruta>
-> --test <ruta>
```

### Criterio PASS por repositorio

- no quedan targets posicionales;
- no quedan aliases eliminados;
- la CI propia pasa;
- documentación y scripts coinciden;
- el cambio no requiere compatibilidad dentro de `testKit`.

## E5 — Runtime Windows real

### Objetivo

Obtener evidencia de Docker Desktop, mounts, quoting, paths y códigos de salida en Windows.

### Criterio PASS

- ejecución en host Windows real o runner equivalente documentado;
- smokes reproducibles;
- artifacts conservados;
- resultado comparable con Linux;
- no se declara paridad por parseo estático solamente.

## E6 — Cutover coordinado

### Precondiciones

- pendientes internos requeridos cerrados;
- consumidores conocidos migrados contra SHA fijo;
- contratos externos ejecutados;
- plan de rollback probado documentalmente.

### Secuencia

1. elegir SHA candidato de `testKit`;
2. adaptar consumidores uno por uno;
3. validar CI y smokes de cada consumidor;
4. actualizar pins o gitlinks mediante commits separados;
5. integrar el corte sin aliases;
6. registrar evidencia final por repositorio.

## E7 — Release o tag contractual

Solo después del cutover:

- decidir si corresponde tag o release;
- documentar último SHA del contrato anterior;
- documentar primer SHA del contrato nuevo;
- publicar notas de ruptura sin prometer compatibilidad inexistente.

## Rollback externo

- volver cada consumidor a su SHA anterior;
- revertir pins o gitlinks de forma explícita;
- no reintroducir aliases en `testKit`;
- no mezclar rollback de un consumidor con cambios en otros repositorios.

## Acciones excluidas desde este pendiente

- modificar `Base`, `Pruebas` o cualquier consumidor sin autorización;
- hacer merge automático;
- actualizar gitlinks junto con cambios internos de submódulos;
- declarar consumidores migrados sin CI o smoke;
- borrar ramas o documentación;
- publicar releases o tags.

## Criterio de cierre

Este pendiente se cierra únicamente cuando cada consumidor conocido tiene evidencia individual de migración o una decisión explícita de no consumo.
