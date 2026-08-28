# SQL Static Audit

TestKit expone una auditoría estática **read-only** para localizar consultas `SELECT`
potencialmente costosas antes de ejecutar la aplicación o conectarse a una base.
Complementa, no reemplaza, el profiling MySQL, `EXPLAIN`, policies, baselines y gates.

## Entry point

```bash
php scripts/sql_static_audit.php --root=/ruta/proyecto --path=back
```

`--path` y `--exclude` son repetibles. Si no se declara `--path`, se audita `.`.
Por defecto se excluyen `.git`, `.testkit`, `vendor`, `node_modules`, `testkit`,
`test` y `tests`.

Formatos:

```bash
php scripts/sql_static_audit.php --root=. --path=src --format=compact
php scripts/sql_static_audit.php --root=. --path=src --format=json
php scripts/sql_static_audit.php --root=. --path=src --json=.testkit/reports/sql_static_audit.json
```

## Contrato v1

Schema:

```text
testkit.sql-static-audit.v1
```

Cada finding contiene:

- `rule_id`;
- `severity`;
- `confidence`;
- `path` y `line`;
- `fingerprint` y `sample_sql` sanitizados;
- evidencia;
- recomendación.

Los literales SQL se sustituyen por `?` en los artefactos. El auditor no persiste
parámetros de prepared statements ni ejecuta las consultas.

## Reglas iniciales

| Regla | Nivel | Confianza | Significado |
|---|---|---|---|
| `select_star` | warn | high | proyección `SELECT *` |
| `unbounded_select` | watch | medium | lectura de tabla sin filtro/agregación/límite explícito |
| `non_sargable_predicate` | warn | high | función como `YEAR(col)` o `LOWER(col)` en predicado |
| `leading_wildcard_like` | warn | high | búsqueda `LIKE '%...'` |

`unbounded_select` es deliberadamente un `watch`: leer una tabla completa puede ser
correcto para catálogos pequeños, exports o procesos batch. Del mismo modo, un
finding de índices no se infiere estáticamente en esta fase.

## Límites

La extracción PHP reconstruye expresiones SQL formadas por strings concatenados y
reemplaza partes dinámicas por `?`. SQL construido mediante builders complejos,
metaprogramación o archivos generados puede quedar fuera de cobertura.

No se afirma:

- que todo `SELECT` necesite `WHERE`;
- que una columna filtrada necesite automáticamente un índice;
- que un finding implique un problema runtime;
- que no tener findings pruebe cobertura SQL total.

N+1, full table scans, uso real de índices, latencia y regresiones deben validarse
con el profiling/`EXPLAIN` runtime existente.

## Exit codes

```text
0 = auditoría ejecutada, incluso con findings
2 = entrada inválida o error operacional
```

No existe gate en v1.
