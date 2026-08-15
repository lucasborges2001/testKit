# Normalización contractual — índice histórico

## Estado

```text
HISTORICO
NO ES BACKLOG ACTIVO
```

Este directorio conserva documentos producidos durante el corte de normalización contractual iniciado el 28 de julio de 2026.

El plan original ya no debe leerse como una lista de fases pendientes: varias etapas fueron implementadas, cerradas o reemplazadas por contratos posteriores.

La autoridad actual del backlog es:

```text
docs/pendientes/README.md
docs/pendientes/normalizacion-contratos/pendiente-interno-testkit.md
docs/pendientes/normalizacion-contratos/pendiente-integraciones-externas.md
```

La autoridad del contrato público vigente es:

```text
docs/CONTRACT_REGISTRY.md
docs/CONTRATO.md
docs/USO.md
```

`docs/CONTRACT_REGISTRY.md` es generado desde `Testkit\Core\Config\ContractRegistry` y no debe sustituirse por listas históricas mantenidas manualmente.

## Baseline histórico

El plan original comenzó sobre:

```text
Repositorio: lucasborges2001/testKit
Commit base histórico: b5b09284c69728dfa93266300f405e3e57157684
Rama histórica: agent/testkit-contract-normalization
```

Esos SHAs sirven como evidencia histórica. No deben reutilizarse como baseline operativo para trabajo nuevo.

## Decisión arquitectónica que se conserva

```text
testKit = plataforma genérica de ejecución, discovery, lifecycle y evidencia
proyecto consumidor = tests, fixtures y reglas de dominio
wrapper Bash/PowerShell = adapters de entrada
JSON versionado = interfaz primaria para automatización
consola = presentación humana no contractual
```

También se conserva la regla de diseño:

- una operación pública debe tener un solo nombre;
- no introducir aliases ni fallbacks silenciosos para evitar migrar consumidores;
- el core no debe absorber dominio de proyectos externos;
- documentación, runtime, schema y tests deben converger en una autoridad verificable.

## Qué se cerró en el corte original

El documento `cierre-corte-2026-07-28.md` registra, entre otros puntos:

- inventario contractual inicial;
- extracción de lógica específica de Tarifa del core;
- registro contractual único para suites, grupos y categorías;
- selector CLI tipado con `--suite`, `--group` y `--category`;
- rechazo de targets posicionales y `TESTKIT_TARGET_*` en la superficie pública;
- migración de comandos internos y UI PowerShell a selectores tipados en las subfases ejecutadas.

Los documentos `fase-*` que describen esas implementaciones son evidencia histórica, no pendientes actuales.

## Deuda interna actual

El backlog interno vigente está consolidado en `pendiente-interno-testkit.md`.

A fecha de este corte documental, contiene como mínimo:

```text
I3  stack estricto
I4  selección única sin bridges TEST_MATCH*
I5  coverage único sin TEST_COVERAGE_DIR ni test/coverage legacy
I6  protocolo de agentes con command_spec neutral
I7  paridad real Bash/PowerShell
I8  reportes y exit codes v2
I9  documentación/gates sin drift
```

Cada fase debe revalidarse contra el HEAD actual antes de implementar. No inferir que sigue pendiente sólo porque aparece en un archivo histórico.

## Deuda externa

`pendiente-integraciones-externas.md` contiene trabajo que requiere cambios o evidencia en consumidores.

Ese documento no autoriza modificar `Base`, `Pruebas` ni otros repositorios desde TestKit.

## Cómo leer los archivos históricos

Los documentos de este directorio pueden contener:

- targets posicionales;
- aliases eliminados;
- variables legacy;
- rutas de coverage antiguas;
- nombres de clases o archivos que ya cambiaron;
- planes de CI que no reflejan el workflow actual.

Cuando un documento histórico contradice el runtime o la documentación vigente:

```text
runtime/registro vigente
> documentación pública actual
> pendiente activo actual
> documento histórico
```

No copiar comandos históricos a nuevas integraciones sin revalidarlos.

## Regla para nuevas actualizaciones

No agregar nuevas fases `fase-*` a este directorio.

Para deuda funcional nueva:

```text
docs/pendientes/<tema>.md
```

o, si pertenece estrictamente a la normalización interna ya consolidada, actualizar:

```text
docs/pendientes/normalizacion-contratos/pendiente-interno-testkit.md
```

Para implementación existente pendiente sólo de evidencia:

```text
docs/verificaciones/
```

## Limpieza futura

Los documentos históricos podrían archivarse o eliminarse en una fase documental separada si existe autorización explícita para borrar archivos.

Hasta entonces se conservan para trazabilidad, pero no forman parte del inventario activo de `docs/pendientes/README.md`.

## No verificado

Esta clasificación documental no demuestra por sí sola:

- PASS de I3-I9;
- CI remota actual;
- runtime Windows completo;
- migración de consumidores externos;
- eliminación de todas las compatibilidades legacy del código.

Esas afirmaciones requieren evidencia separada.