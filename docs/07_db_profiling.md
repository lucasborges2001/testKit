# 07 — DB profiling (queries / índices)

Objetivo: que el entorno de tests te deje ver, de forma **reproducible**, cosas como:

- cuántas queries dispara un flujo
- cuáles son las **tablas más “calientes”**
- qué columnas aparecen una y otra vez en WHERE/JOIN/ORDER
- cuáles son las queries más lentas

Esto ayuda a responder:

- “¿vale la pena un índice?”
- “¿cuál columna conviene indexar primero?”
- “¿tengo N+1 queries?”

> Regla: decidir índices **no** es solo frecuencia. Cruzá frecuencia + selectividad + tamaño de tabla + `EXPLAIN`.

---

## A) Profiling a nivel app (portable)

El kit trae `test/utils/php/db_profiler.php`.

### 1) Activar

En `test/.env.test`:

```env
TEST_DB_PROFILE=1
```

### 2) Usar en tests

En tus tests que ejecutan SQL, usá:

```php
require_once __DIR__ . '/../../utils/php/db_profiler.php';
$db = tk_pdo();
```

`tk_pdo()` devuelve:

- un `PDO` normal si `TEST_DB_PROFILE=0`
- un `PDO` profilado si `TEST_DB_PROFILE=1` (log JSONL)

### 3) Generar reporte

```bash
php test/scripts/query_report.php
```

Salida:

- top tablas por hits
- top `table.col`
- top queries lentas

### Limitaciones

- El “parseo” de columnas es heurístico (regex). Sirve para *detectar patrones*.
- Para diagnóstico final, mirá:
  - `EXPLAIN` / `EXPLAIN ANALYZE`
  - tamaños reales de tablas
  - cardinalidad/selectividad

---

## B) Complemento MySQL (slow log + performance schema)

Este kit habilita `slow_query_log` en el contenedor MySQL (ver `test/mysql/conf.d/testkit.cnf`).

### Ver slow log

Dentro del contenedor MySQL:

```bash
cd test
./bin/testkit exec -T mysql_test sh -lc 'tail -n 200 /var/lib/mysql/slow.log'
```

> Si querés ajustar el umbral: cambiá `long_query_time` en `test/mysql/conf.d/testkit.cnf`.

---

## Tips para “decidir índices” (práctico)

1) Identificá queries frecuentes y lentas.
2) Mirá columnas repetidas en WHERE/JOIN.
3) Corré `EXPLAIN`.
4) Si el plan muestra table scan y hay filtro selectivo → candidato fuerte.
5) Evitá índices de baja selectividad (ej: flags booleanos) salvo casos particulares.


## Candidatos de índices (heurístico)

El script `test/scripts/query_report.php` ahora separa columnas por contexto:

- **WHERE**: filtros (candidatos clásicos de índice)
- **JOIN/ON**: claves de join (candidatos fuertes)
- **Combos**: columnas que aparecen **juntas** en el mismo WHERE (candidatos de índice compuesto)

> Importante: esto **no decide** el índice. Es un *backlog* para revisar con `EXPLAIN` / `EXPLAIN ANALYZE` y cardinalidad.

### Flujo recomendado

1) Corré los tests con profiling:

```bash
TEST_DB_PROFILE=1 ./bin/testkit run --rm testkit php runTest.php back
```

2) Generá reporte:

```bash
./bin/testkit run --rm testkit php scripts/query_report.php
```

3) Para los top 5 candidatos:
- buscá la query real (sección *Slow queries*)
- corré `EXPLAIN` en MySQL / `EXPLAIN (ANALYZE, BUFFERS)` en Postgres
- verificá **selectividad** (si una columna tiene pocos valores distintos, el índice puede no ayudar)
- si el ORDER BY es recurrente, evaluá un índice que cubra (WHERE + ORDER BY)

### N+1
Si ves muchas queries parecidas cambiando solo el id (y hits altos en una tabla), es un smell de **N+1**. Ahí el fix suele ser:
- join/IN/batch fetch
- caché en memoria en el request
- o re-diseñar la consulta


## EXPLAIN sugerido (automático)

El reporte `scripts/query_report.php` imprime una línea `EXPLAIN ...;` (o `EXPLAIN (ANALYZE, BUFFERS) ...;` en Postgres) debajo de cada slow query.

> Es una normalización heurística: reemplaza literales/números por `?` y colapsa listas `IN (...)`. Usalo como punto de partida; si la query tiene placeholders reales, ajustala antes de correr EXPLAIN.
