# SQL Observability Fase 4 — baselines y comparación

## Alcance

Se incorporó a `testKit` una capa report-only para crear baselines SQL explícitos y comparar un perfil actual contra una referencia compatible.

## Componentes

- builder y loader del contrato `mysql-query-baseline-v1`;
- evaluación separada de compatibilidad;
- identidad estable por query ID o fingerprint;
- comparación global y por query con deltas absolutos/porcentuales;
- normalización y comparación estructural de planes;
- detección de queries nuevas, removidas y ambiguas;
- artifact `mysql-query-comparison-report-v1`;
- integración opcional y acotada en el reporte v2;
- CLI de creación y comparación;
- fixtures y contrato framework.

## Compatibilidad

- no cambia la API pública de captura;
- no cambia `report_version: 2`;
- policies y comparación se evalúan por motores separados;
- sin baseline configurado no se genera artifact adicional;
- reportes v1 quedan en modo legacy/estructural;
- una regresión mantiene exit code `0`.

## Seguridad

No se persisten parámetros, credenciales, DSN, hostname privado, payloads ni paths absolutos. Los planes se reducen a estructura normalizada y los artifacts se publican atómicamente.

## Fuera de alcance

No se agregaron gates, auto-accept, promoción automática, comentarios de PR, tendencias multi-run, dashboard, DDL ni reescritura SQL.
