# 0150 — Comandos `inspect`

## Problema

Hoy se mezclan responsabilidades: ejecutar, diagnosticar y reconstruir contexto. Eso obliga a usar grep, abrir archivos y navegar reportes a mano.

## Objetivo

Agregar subcomandos de inspección de estado y artefactos.

## Checklist

- [ ] Diseñar `testkit inspect run <id>`.
- [ ] Diseñar `testkit inspect latest`.
- [ ] Diseñar `testkit inspect failure --latest`.
- [ ] Diseñar `testkit inspect seed-state`.
- [ ] Diseñar `testkit inspect concurrency`.
- [ ] Diseñar `testkit inspect catalog <backend>` si aplica.
- [ ] Hacer que todos puedan responder en formato texto y JSON.

## Ejemplos de CLI

```bash
testkit inspect latest --json
testkit inspect failure --latest --json
testkit inspect seed-state --suite back-php --json
testkit inspect concurrency --json
```

## Criterio de aceptación

La mayoría del diagnóstico estructural puede hacerse sin leer directamente archivos bajo `.testkit/reports/`.
