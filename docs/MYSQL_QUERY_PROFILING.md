# MySQL Query Profiling e instrumentación observable

TestKit expone profiling MySQL **opt-in** para suites PHP. Esta fase conserva el diagnóstico de latencia y `EXPLAIN`, y agrega telemetría auditable sobre cómo se capturó cada consulta, qué contexto estuvo disponible, qué conexiones fueron observadas y qué limitaciones impiden afirmar cobertura total.

No implementa baselines, comparación entre commits, budgets, gates, creación de índices ni cambios de schema.

## Activación

```bash
TESTKIT_DB_PROFILE=1 php runTest.php back-php
php scripts/query_report.php
php scripts/query_instrumentation_audit.php
```

`TESTKIT_DB_PROFILE` permanece apagado por defecto. Cuando está desactivado, el collector retorna antes de crear estructuras o artefactos.

## Flujo

```text
BackPhpSuite
  -> prepareRun(): directorio aislado por run_id + .session.json
  -> auto_prepend_mysql_profile.php
  -> helpers/adaptadores instrumentados
  -> QueryProfileCollector por proceso
  -> shard JSON atómico
  -> MysqlProfileReporter consolida solo run/session actuales
  -> latest + history + CLI humano + CLI de auditoría
```

El marcador `.session.json` contiene únicamente configuración pública efectiva y metadatos operacionales. Los workers recuperan de allí defaults no propagados explícitamente por el proceso padre; credenciales y DSN completos nunca se escriben.

## Métodos de captura estables

| Método | Origen | Cobertura observable |
|---|---|---|
| `profiled_pdo.query` | `tk_profiled_pdo()` / `PDO::query()` | query directa |
| `profiled_pdo.exec` | `tk_profiled_pdo()` / `PDO::exec()` | exec directa |
| `profiled_pdo.statement_execute` | statement creado por `ProfiledPDO::prepare()` | execute preparado |
| `existing_pdo.statement_execute` | `tk_mysql_profile_enable_pdo()` | solo statements preparados después del helper |
| `mysqli.query.manual` | `tk_mysql_profile_mysqli_record_query()` | hook manual alrededor de query |
| `mysqli.statement_execute.manual` | remember + record execute | hook manual de statement |
| `manual.record` | `tk_mysql_profile_record()` | hook explícito |
| `unknown` | método inválido/no declarado | genera finding |

Una familia agrupada por fingerprint conserva `capture_methods` con conteos por método.

## API pública compatible

Las firmas anteriores siguen siendo válidas; el contexto nuevo es opcional.

```php
$pdo = tk_profiled_pdo($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$pdo = tk_mysql_profile_enable_pdo($pdo);

tk_mysql_profile_record(
    $sql,
    $durationMs,
    'src/Catalog/ProductRepository.php',
    'test/back/catalog/ProductSearch.test.php:42',
    [
        'module_id' => 'catalog',
        'scenario_id' => 'product_search',
        'capture_method' => 'manual.record',
    ]
);
```

Para `mysqli`:

```php
$started = microtime(true);
$result = mysqli_query($conn, $sql);
tk_mysql_profile_mysqli_record_query(
    $sql,
    (microtime(true) - $started) * 1000,
    'src/CatalogRepository.php'
);

$stmt = $db->prepare($sql);
tk_mysql_profile_mysqli_remember($stmt, $sql, ['module_id' => 'catalog']);
$started = microtime(true);
$stmt->execute();
tk_mysql_profile_mysqli_record_execute($stmt, (microtime(true) - $started) * 1000);
tk_mysql_profile_mysqli_forget($stmt);
```

No se persisten parámetros enviados a `execute()`.

## Contexto normalizado

Cada agregado puede conservar, con cardinalidad limitada:

- `run_id`, `meta_run_id`, `suite_id`;
- `test_id`/`test_path` relativo;
- `worker_id`, `process_id`;
- `module_id`, `scenario_id`;
- `source`, `caller`;
- `capture_method`;
- `connection_id` anonimizado.

Rutas absolutas dentro del repositorio se vuelven relativas. Rutas externas se reducen al basename. Los textos se limitan a 160–240 caracteres según el campo y cada lista de contexto usa `TESTKIT_DB_PROFILE_MAX_CONTEXT_VALUES` (default `20`). Cuando se supera el límite aparece `context_cardinality_truncated`.

No enviar headers, cookies, payloads, parámetros SQL ni secretos en el contexto. Las claves sensibles (`password`, `token`, `api_key`, `dsn`, etc.) se descartan.

## Registro de conexiones

`connections[]` informa:

- ID efímero hash por run/proceso/objeto;
- adapter y engine;
- capacidades `query`, `exec`, `prepare_execute`, `transactions`;
- timestamps de creación/primera/última consulta;
- conteos de queries, prepares y operaciones transaccionales;
- `instrumented`.

`tk_mysql_profile_enable_pdo()` declara honestamente captura parcial: no puede interceptar `query()`/`exec()` directos ni statements creados antes de habilitarlo.

## Cobertura observable

`coverage.facts` contiene hechos medidos sobre **consultas capturadas** y conexiones registradas. `coverage.calculable` calcula completitud de source/caller/test/connection/module/scenario usando como denominador las consultas capturadas.

La cobertura total de la aplicación queda explícitamente desconocida:

```json
{
  "total_application_queries": null,
  "overall_capture_coverage_pct": null,
  "overall_capture_coverage_status": "unknown",
  "reason": "PHP userland cannot observe queries executed outside instrumented adapters"
}
```

Un `100%` de contexto significa que todas las consultas **capturadas** tienen ese contexto; no prueba que toda consulta real haya sido capturada.

## Estadísticas por fingerprint

Además de `calls`, `min_ms`, `avg_ms`, `max_ms` y `total_ms`, cada fingerprint incluye:

- `p50_ms`, `p95_ms`, `p99_ms`;
- `standard_deviation_ms`;
- `sample_count`;
- `percentiles_approximate`.

Se usa muestreo determinista acotado: se conservan las observaciones con menor prioridad SHA-256 hasta `TESTKIT_DB_PROFILE_SAMPLE_LIMIT` (default `256`). La selección es estable frente al orden de consolidación. Si `calls > sample_count`, los percentiles se marcan aproximados. Con una muestra, los tres percentiles coinciden y la dispersión es cero.

## Shards y concurrencia

Ruta default:

```text
.testkit/mysql_profile/shards/<run_id>/
```

Características:

- directorio aislado por `run_id`;
- marcador de sesión único;
- nombres con run, hash de sesión, PID y token aleatorio;
- escritura temporal con `LOCK_EX` y `rename()` atómico;
- no se eliminan shards de otra ejecución;
- shards de otro run/sesión se ignoran y se contabilizan;
- JSON corrupto genera `corrupt_shard`;
- configuraciones distintas entre workers generan `inconsistent_worker_configuration`;
- consolidación y rankings tienen desempate determinista.

## Findings de instrumentación

Códigos principales:

- `existing_pdo_partial_capture`;
- `manual_record_missing_origin`;
- `unknown_capture_method`;
- `bootstrap_not_confirmed`;
- `missing_shards`;
- `corrupt_shard`;
- `foreign_shards_ignored`;
- `inconsistent_worker_configuration`;
- `connection_without_queries`;
- `query_without_connection`;
- `context_cardinality_truncated`;
- `collector_record_error`;
- `report_build_failed`.

Cada finding incluye `code`, `severity`, `message`, `context` sanitizado y `recommendation`. Son diagnósticos; no fallan CI en esta fase.

## Variables de entorno

| Variable | Default | Función |
|---|---:|---|
| `TESTKIT_DB_PROFILE` | `0` | activación principal |
| `TESTKIT_DB_PROFILE_CONTEXT` | `1` | contexto test/source/caller/module/scenario |
| `TESTKIT_DB_PROFILE_CONNECTIONS` | `1` | registro de conexiones |
| `TESTKIT_DB_PROFILE_CAPTURE_CALLER` | `1` | backtrace acotado para caller |
| `TESTKIT_DB_PROFILE_CAPTURE_SOURCE_TEST` | `1` | source/test automático |
| `TESTKIT_DB_PROFILE_SAMPLE_LIMIT` | `256` | muestras por fingerprint |
| `TESTKIT_DB_PROFILE_MAX_CONTEXT_VALUES` | `20` | cardinalidad por campo/fingerprint |
| `TESTKIT_DB_PROFILE_MAX_SQL_LENGTH` | `2000` | longitud máxima de muestra sanitizada |
| `TESTKIT_DB_PROFILE_TOP_N` | `20` | tamaño de rankings |

Los thresholds y flags `EXPLAIN` existentes se preservan. La configuración efectiva pública aparece en `config`; DSN, usuario y contraseña quedan excluidos.

## Reportes y CLI

```text
.testkit/reports/mysql_profile_latest.json
.testkit/history/mysql_profile/mysql_profile_<timestamp>_<token>.json
```

```bash
php scripts/query_report.php
php scripts/query_report.php --path /tmp/mysql_profile.json

php scripts/query_instrumentation_audit.php
php scripts/query_instrumentation_audit.php --path /tmp/mysql_profile.json
```

`query_instrumentation_audit.php` retorna:

- `0`: reporte válido, incluso con warnings;
- `2`: archivo inexistente o JSON inválido;
- `3`: contrato corrupto/incompatible.

`query_report.php` tolera reportes v1; campos de instrumentación faltantes se muestran como legacy/unknown.

## Seguridad

- literales SQL se sustituyen por `?`;
- comentarios se eliminan;
- emails, UUID, fechas, números, booleanos y tokens se normalizan;
- parámetros de prepared statements nunca se almacenan;
- configuración pública omite credenciales/DSN;
- errores y salida `EXPLAIN` se sanitizan recursivamente;
- rutas absolutas se normalizan;
- archivos operacionales se escriben con permiso objetivo `0640` cuando el filesystem lo permite;
- `EXPLAIN` solo usa credenciales explícitas de test y conserva las restricciones existentes.

## Troubleshooting

- `missing_shards`: confirmar `auto_prepend_file`, permisos y `TESTKIT_DB_PROFILE=1` en workers.
- `bootstrap_not_confirmed`: ejecutar mediante la suite oficial o cargar `public_api.php` antes del acceso SQL.
- `existing_pdo_partial_capture`: migrar la factory a `tk_profiled_pdo()`.
- `query_without_connection`: usar wrappers registrados o enviar `connection_id` desde un adaptador propio.
- `corrupt_shard`: revisar terminación abrupta/filesystem; el resto de shards válidos se conserva.
- cobertura total `unknown`: comportamiento correcto mientras no exista denominador independiente.

## Integración futura

La Fase 2 debe adaptar el consumo desde Base, auditar sus factories PDO/mysqli, centralizar el bootstrap y definir el contrato host/Base para `module_id` y `scenario_id`. La secuencia Git definitiva es `testKit -> gitlink testkit en Base -> gitlink Base en Pruebas`.

## Políticas declarativas y budgets — Fase 3

La clasificación del profiler y las policies son conceptos distintos:

```text
classification      = heurística general del profiler
policy_evaluation   = expectativa declarada por el consumidor
```

Una query puede ser `classification=ok` y violar `max_calls`; también puede ser `classification=slow` y cumplir una policy explícita.

### Activación

```bash
TESTKIT_DB_PROFILE=1 \
TESTKIT_DB_PROFILE_POLICY_FILE=test/sql/mysql-profile-policies.json \
php runTest.php back-php
```

Luego:

```bash
php scripts/query_policy_report.php
```

Evaluación explícita:

```bash
php scripts/query_policy_report.php \
  --profile .testkit/reports/mysql_profile_latest.json \
  --policy test/sql/mysql-profile-policies.json
```

Variables:

```text
TESTKIT_DB_PROFILE_POLICY_FILE
TESTKIT_DB_PROFILE_POLICY_MODE=report_only
TESTKIT_DB_PROFILE_POLICY_REPORT_PATH
TESTKIT_DB_PROFILE_POLICY_HISTORY_PATH
TESTKIT_DB_PROFILE_POLICY_MAX_RESULTS
```

Sin `TESTKIT_DB_PROFILE_POLICY_FILE`, la evaluación permanece desactivada. `report_only` es el único modo admitido en esta fase; `fail` y `enforce` se rechazan.

### CLI

```text
--profile
--policy
--format=human|json
--json=<path>
--show-passed
--show-unused
--top=<n>
--help
```

Exit codes:

```text
0 evaluación ejecutada, incluso con violations
2 error operacional
3 policy/contrato inválido
4 profile incompatible no recuperable
```

### Precedencia y merge

Las policies se ordenan por especificidad calculada, no por orden del JSON. Los budgets generales se heredan y una policy más específica reemplaza solo las claves que declara. El resultado registra el origen de cada budget efectivo.

### Evidencia insuficiente

La ausencia de percentiles, contexto o EXPLAIN no es un pass. Se informa `insufficient_data` o `not_evaluated` si la policy configuró `on_insufficient_data=ignore`.

### Contrato

Ver:

```text
docs/contracts/mysql-query-policy-v1.md
```

### Limitaciones de Fase 3

- no hay baseline ni comparación entre commits;
- no hay gates obligatorios;
- no hay dashboard;
- no hay sugerencias ni creación automática de índices;
- no se ejecuta SQL desde policies.
