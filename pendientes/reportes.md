# reportes.md

Quiero que trabajes **solo sobre el repo actual de `testkit`** y cierres de verdad el problema de reporting/retención para dejarlo listo para uso serio en producción.

## Contexto real que ya detecté

En el estado actual de `testkit`, los outputs siguen viviendo por defecto dentro del árbol `test/` del proyecto host, incluyendo reportes, historial, coverage y query profiling. El README actual todavía documenta outputs como:

- `test/reports/*_latest.json` o `test/<side>/<module>/report/*_latest.json`
- `test/history/*.json`
- `test/coverage/*`
- `test/querylog.jsonl`

y además afirma que los outputs pertenecen al directorio `test/` del host. Eso hoy es parte del problema, no una virtud. Verificalo en el repo actual y no lo aceptes como axioma correcto. Si la raíz de artifacts está mal elegida, decilo con claridad.

## Problema a resolver

`testkit` ensucia el repo host porque usa el árbol versionable del proyecto para guardar estado operativo:

- reportes latest
- reportes timestamped
- índices de corridas
- historial
- profiling/query log
- potencialmente otros artifacts relacionados

Además, el consolidado humano (`scripts/report.php`) hoy puede terminar leyendo `*_latest.json` dispersos por múltiples roots/directorios, lo que genera riesgo de mezcla de reportes viejos + nuevos y hace que el problema no sea solo de Git sino también de lectura operativa.

## Objetivo

Implementar en `testkit` una política de reporting y retención **pequeña, coherente, segura y realmente productiva**.

## Decisión esperada de diseño

Quiero que cuestiones las opciones y elijas la correcta. No me valides por defecto.

La dirección que espero que evalúes críticamente y, si confirma el diagnóstico, implementes es esta:

- mover el estado operativo de reporting a una raíz efímera e ignorada del host, por default `/.testkit/`
- separar estado corto útil de historial
- mantener `latest` canónico por suite
- permitir latest scopeado solo cuando aporta diff útil real
- consolidar todo en una raíz central, no seguir multiplicando directorios físicos por módulo
- conservar solo los últimos 5 históricos relevantes por clave lógica
- dejar `scripts/report.php` funcionando bien con la nueva política
- mantener compatibilidad razonable de transición con layout legacy si hace falta, pero sin seguir escribiendo al layout viejo por default

## Requisitos obligatorios

### 1. Raíz de artifacts
Implementá una raíz central para artifacts operativos, por ejemplo:

- `/.testkit/reports/`
- `/.testkit/history/`

No quiero que por default siga escribiendo en:

- `test/reports/`
- `test/<side>/<module>/report/`
- `test/history/`

Puede haber compatibilidad de lectura legacy, pero no default de escritura legacy.

### 2. Configuración
Permití una sola variable clara para override del root, por ejemplo:

- `TESTKIT_ARTIFACTS_ROOT`

No metas una explosión de configuración nueva.

### 3. Latest canónico
Quiero un latest canónico por suite, por ejemplo:

- `back_php_latest.json`
- `back_python_latest.json`
- `front_php_latest.json`
- `front_js_latest.json`
- `meta_latest.json`

Si hay corrida scopeada por módulo y eso sirve para el diff útil, acepto un latest scopeado adicional con nombre determinista, por ejemplo:

- `back_php__back_auth_latest.json`

Pero el consolidado no debe depender de andar recorriendo directorios físicos por módulo.

### 4. Timestamped + retención
Conservar solo los últimos 5 timestamped por clave relevante.

No quiero prune cosmético por carpeta.
Quiero retención por clave lógica, por ejemplo:

- suite global
- suite + scope
- meta + target + scope

### 5. Índice compacto
Mantener `runs_latest.json` útil y corto.

### 6. Historial
Mantener `history` necesario para fragility hints, pero centralizado bajo la raíz nueva.

### 7. Consolidado humano
`scripts/report.php` debe seguir siendo útil.
Quiero que priorice la raíz canónica nueva y use el layout legacy solo como fallback transitorio si hace falta.

Debe evitar mezclar o duplicar suites por encontrar múltiples `*_latest.json` viejos en distintos lugares.

### 8. Query profiling
Revisá también `scripts/query_report.php` y el path por defecto de `test/querylog.jsonl`.

No ignores este punto: también es artifact operativo.
Si corresponde, movelo al nuevo root efímero o dejá explícito por qué no se mueve. Pero no lo pases por alto.

### 9. Docs
Actualizá la documentación afectada para que el contrato quede alineado con el comportamiento real.

Como mínimo revisá:

- `README.md`
- `docs/USO.md`
- `docs/REPORTING_COVERAGE.md`

No quiero docs que sigan diciendo que el output real vive en `test/reports` si eso deja de ser verdad.

### 10. Tests
Si hoy no existen tests de regresión de reporting/retention, agregalos.

Quiero cobertura mínima para estos casos:

- varias corridas globales conservan solo 5 timestamped
- varias corridas scopeadas conservan solo 5 por scope
- `runs_latest.json` queda acotado
- `meta_latest.json` sigue consistente
- `scripts/report.php` no duplica ni mezcla suites cuando conviven latest canónicos y scopeados
- fallback legacy no rompe
- paths normalizados siguen siendo robustos en Linux/Windows

## Restricciones

- trabajar **solo en `testkit`**
- no tocar `Kiara`
- no mezclar este cambio con UI interactiva
- no mezclar este cambio con PowerShell salvo que sea estrictamente necesario por compatibilidad del contrato
- no depender de limpieza manual
- no inventar scripts externos ad hoc
- no romper el flujo actual de `php runTest.php`
- no romper `scripts/report.php`
- no romper consumo por agentes/herramientas que leen `latest`, `runs_latest.json` y diff contra corrida previa

## Criterios de aceptación

El cambio se considera bien hecho solo si se cumple todo esto:

- `testkit` deja de escribir por default reportes/historial en el árbol versionable `test/` del host
- existe una raíz central y clara para artifacts operativos
- se conserva `latest` útil
- se conserva un índice corto de corridas
- se conserva historial corto y/o el historial necesario para fragility hints
- se conservan solo los últimos 5 históricos relevantes por clave lógica
- `scripts/report.php` sigue funcionando y mejora su lectura operativa
- no queda dependencia en directorios físicos por módulo como fuente principal de verdad
- el contrato queda documentado y probado

## Expectativa de criterio técnico

No me digas “agregué `.gitignore` y listo”.
No me digas “ya había prune”.
No asumas que mantener reportes dentro de `test/` es correcto solo porque así estaba antes.

Quiero que busques la verdad incómoda:
si el problema central es que el report root está mal elegido, decilo y corregilo.

## Forma de trabajo

1. Hacé un diagnóstico breve y específico del problema actual.
2. Elegí una política de diseño concreta.
3. Implementala en el repo actual de `testkit`.
4. Agregá o ajustá tests necesarios.
5. Ajustá docs necesarias.
6. Devolveme solo:

- diagnóstico breve
- decisión de diseño
- archivos nuevos/modificados
- contenido completo de cada archivo nuevo/modificado
- qué conserva
- qué poda
- qué cambia del contrato
- riesgos/límites

## Importante

No me des una respuesta genérica.
No me des pseudocódigo.
No me des solo recomendaciones.
Quiero implementación real sobre el repo actual de `testkit`, con cambios chicos pero de alto impacto.