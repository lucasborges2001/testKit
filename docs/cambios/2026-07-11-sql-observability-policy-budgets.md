# SQL Observability Fase 3 — Policies y budgets

## Alcance

Se incorporó a testKit un motor declarativo `mysql-query-policy-v1` que evalúa reportes MySQL v1/v2 en modo `report_only`.

## Componentes

- configuración por entorno integrada con `MysqlProfileConfig`;
- loader JSON estricto con errores por ruta;
- matching por fingerprint, query ID y contexto;
- precedencia determinista y merge por clave;
- budgets por fingerprint y globales;
- restricciones de EXPLAIN;
- sección compatible `policy_evaluation` en reporte v2;
- artefacto separado atómico;
- CLI humana/JSON;
- resumen opcional en attachment de suite;
- fixtures y tests contractuales.

## Compatibilidad

- no se modifica el exit code por violations;
- no se altera `classification`;
- sin policy file no se generan artefactos de policy;
- reportes v1 quedan en modo legacy y nunca convierten campos ausentes en pass;
- `query_report.php` y `query_instrumentation_audit.php` continúan operando.

## Seguridad

No se persisten parámetros SQL, credenciales, payloads ni rutas absolutas. El archivo de policy se identifica por ruta normalizada y SHA-256.

## Fuera de alcance

No se implementaron baselines, regresiones, gates, dashboard, índices automáticos ni migraciones.
