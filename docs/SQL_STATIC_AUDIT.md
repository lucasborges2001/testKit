# SQL Static Audit

TestKit expone una auditoría estática **read-only** para localizar consultas `SELECT`
potencialmente costosas, gaps de cobertura y patrones de uso sospechosos antes de
conectarse a una base.

Complementa, no reemplaza, profiling MySQL, `EXPLAIN`, policies, baselines runtime
y quality gates.

## Entry point

Suite canónica (por defecto audita `TESTKIT_PROJECT_ROOT` completo):

```bash
php runTest.php --suite sql-static-audit
TESTKIT_CONSOLE_MODE=live php runTest.php --suite sql-static-audit
```

Configuración opcional, con listas separadas por coma para paths/excludes múltiples:

```text
TESTKIT_SQL_STATIC_PATH=back,modules/example
TESTKIT_SQL_STATIC_EXCLUDE=back/generated,tmp
TESTKIT_SQL_STATIC_BASELINE=.testkit/reports/sql-static-baseline.json
TESTKIT_ARTIFACTS_ROOT=/ruta/artifacts-testkit
```

`TESTKIT_PROJECT_ROOT` y `TESTKIT_ARTIFACTS_ROOT` tienen responsabilidades distintas:

```text
TESTKIT_PROJECT_ROOT   = código que se analiza
TESTKIT_ARTIFACTS_ROOT = estado y artifacts que TestKit escribe
```

Si `TESTKIT_ARTIFACTS_ROOT` no se define, TestKit usa `<project>/.testkit`. Si se
define, la suite respeta ese root y no necesita crear `.testkit` dentro del
consumidor analizado.

La suite persiste `suite-report.json` y `sql-static-audit.json` bajo:

```text
<artifacts-root>/reports/sql-static-audit/<run_id>/
```

No pertenece todavía a `group all` ni a categorías; se selecciona explícitamente.

### Adopción multi-consumidor

Un host puede reutilizar una sola instalación de TestKit para auditar varios
repositorios/submódulos sin copiar el auditor dentro de cada consumidor. Ejemplo:

```bash
TESTKIT_PROJECT_ROOT=/ruta/host/submodules/Contabilidad \
TESTKIT_ARTIFACTS_ROOT=/ruta/host/.testkit/sql-static/Contabilidad \
TESTKIT_SQL_STATIC_PATH=. \
NO_COLOR=1 \
php /ruta/host/submodules/Base/testkit/runTest.php --suite sql-static-audit
```

Este patrón mantiene separado el ownership:

- el consumidor solo aporta código fuente;
- TestKit conserva scanner, extractor, reglas, cobertura, `stable_id`, baseline/delta y schema;
- el host decide dónde centralizar artifacts y qué módulos auditar.

El entrypoint standalone conserva sus formatos y usa el mismo renderer visual:

```bash
php scripts/sql_static_audit.php --root=/ruta/proyecto --path=back
```

`--path` y `--exclude` son repetibles. Por defecto se excluyen `.git`, `.testkit`,
`vendor`, `node_modules`, `testkit`, `test` y `tests`.

Formatos:

```bash
php scripts/sql_static_audit.php --root=. --path=src --format=compact
php scripts/sql_static_audit.php --root=. --path=src --format=json
php scripts/sql_static_audit.php --root=. --path=src --json=.testkit/reports/sql_static_audit.json
```

Comparación informativa contra un reporte previo:

```bash
php scripts/sql_static_audit.php \
  --root=. \
  --path=src \
  --baseline=.testkit/reports/sql_static_baseline.json \
  --format=compact
```

## Contrato compatible

El schema público sigue siendo:

```text
testkit.sql-static-audit.v1
```

La evolución actual es aditiva: conserva `scanned_files`, `extracted_queries`,
`summary` y `findings` y agrega cobertura, `stable_id` y `delta`.

Campos de cobertura:

```text
sql_candidates
extracted_queries
unresolved_candidates
coverage_status = best_effort|partial
coverage_findings[]
```

`best_effort` no significa cobertura total. Solo indica que el auditor no observó
un callsite dinámico reconocido que quedara sin reconstruir.

Cada finding SQL contiene:

- `id`: identidad de ocurrencia, sensible a línea;
- `stable_id`: identidad por regla + path + fingerprint para baseline;
- `rule_id`, `severity`, `confidence`;
- `path`, `line`;
- `fingerprint`, `sample_sql` sanitizados;
- evidencia y recomendación.

Los literales SQL se sustituyen por `?`. No se persisten parámetros de prepared
statements ni se ejecutan consultas.

## Reglas SQL

| Regla | Nivel | Confianza | Significado |
|---|---|---|---|
| `select_star` | warn | high | proyección `SELECT *` |
| `unbounded_select` | watch | medium | lectura sin filtro/agregación/límite explícito |
| `non_sargable_predicate` | warn | high | función sobre columna en `WHERE`/`ON` |
| `leading_wildcard_like` | warn | high | búsqueda `LIKE '%...'` |
| `order_by_random` | watch | high | `ORDER BY RAND()`/`RANDOM()` |
| `offset_pagination` | watch | medium | paginación basada en `OFFSET` |
| `query_inside_loop` | watch | medium | query declarada dentro de `for`/`foreach`/`while`/`do` |

`query_inside_loop` no afirma N+1. El profiler runtime debe confirmar cantidad de
calls/fingerprints antes de tratarlo como defecto.

`offset_pagination` tampoco es un error automático: es una señal para revisar
páginas profundas y considerar keyset/seek pagination cuando corresponda.

## Gaps de cobertura

`coverage_findings` se separa de los findings SQL. La señal inicial es:

```text
dynamic_sql_unresolved
```

Aparece cuando un callsite reconocido (`query`, `prepare`, wrappers Base, etc.)
recibe una expresión que el auditor no puede reconstruir como `SELECT` literal.
No se clasifica como defecto de SQL y no participa del gate.

Cada coverage finding agrega un `reason` estable para explicar el límite local:

| Reason | Significado |
|---|---|
| `parameter_passthrough` | el SQL llega como parámetro del wrapper y requiere inspeccionar callers |
| `external_statement` | el statement deriva de contenido leído desde un archivo |
| `dynamic_expression` | la expresión dinámica no entra en las categorías anteriores |

La clasificación sigue únicamente asignaciones y `foreach` simples dentro del
archivo. No realiza análisis interprocedural ni intenta leer el SQL externo. Las
declaraciones de wrappers no cuentan como callsites.

Asignaciones simples como:

```php
$sql = 'SELECT id FROM users WHERE id = ?';
$pdo->query($sql);
```

se reconocen como SQL conocido para evitar ruido de cobertura.

## Baseline / delta

`--baseline=<report.json>` compara mediante `stable_id` y agrega:

```text
delta.status = compared
new_findings
resolved_findings
unchanged_findings
changes[]
gate_enabled = false
```

El baseline es informativo: nuevos findings no cambian el exit code. La comparación
actual usa `findings[]`; los gaps de `coverage_findings[]` continúan informándose
como cobertura y no participan todavía del delta.

## Arquitectura

Las reglas SQL viven separadas en:

```text
core/php/sqlstatic/rules/
```

`SqlRuleRegistry` las compone. `SqlRuleSet` se mantiene como facade compatible para
consumidores existentes.

El contexto PHP, cobertura y baseline están separados de las reglas SQL para que
el auditor no derive en un único archivo monolítico.

## Límites

Builders complejos, metaprogramación, SQL generado fuera de PHP/SQL reconocido o
flujo de variables no trivial pueden quedar fuera de cobertura.

No se afirma:

- que todo `SELECT` necesite `WHERE`;
- que una columna filtrada necesite automáticamente un índice;
- que un finding implique un problema runtime;
- que `best_effort` pruebe cobertura SQL total;
- que una query dentro de un loop sea necesariamente N+1.

N+1 real, full scans, índices usados, latencia y regresiones deben confirmarse con
profiling/`EXPLAIN` runtime.

## Exit codes

```text
0 = auditoría ejecutada, incluso con findings/delta
2 = entrada inválida o error operacional
```

No existe gate SQL estático en esta fase.
