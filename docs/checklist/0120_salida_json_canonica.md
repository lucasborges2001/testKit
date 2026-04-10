# 0120 — Salida JSON canónica del run

## Problema

El runner tiene reportes, pero el agente necesita abrir artefactos secundarios para descubrir información básica: primer fallo, seed mode, warnings relevantes, validez del contexto.

## Objetivo

Agregar una salida JSON canónica en stdout o como artefacto principal de primer nivel.

## Checklist

- [ ] Definir `--json` o `--output=json` como contrato estable.
- [ ] Incluir `run_id`, suite, selección efectiva y tiempos.
- [ ] Incluir estado final canónico y equivalente legacy si hace falta.
- [ ] Incluir primer fallo útil, no solo contadores.
- [ ] Incluir advertencias clasificadas por severidad.
- [ ] Incluir `report_root` y paths de artefactos relevantes.
- [ ] Versionar el formato (`report_version`).
- [ ] Documentar compatibilidad hacia atrás.

## Ejemplo mínimo

```json
{
  "report_version": 1,
  "run_id": "20260410T180528Z_92db4e",
  "suite": "back-php",
  "selected_tests": 1,
  "failed_files": 0,
  "final_status": "PASS",
  "store_mode": "shared",
  "seed_mode": {
    "baseline": "mysql",
    "requested_migrations": [],
    "applied_migrations": []
  },
  "first_failure": null,
  "warnings": [
    {
      "code": "ORPHAN_CONTAINERS_FOUND",
      "severity": "warn",
      "blocking": false
    }
  ],
  "artifacts": {
    "report_root": ".testkit/reports/runs/20260410T180528Z_92db4e"
  }
}
```

## Criterio de aceptación

Un agente puede decidir el siguiente paso sin abrir logs ni carpetas secundarias en al menos el 80% de los runs comunes.
