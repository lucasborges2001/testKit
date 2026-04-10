# 0180 — Modo `agent-run`

## Problema

Un agente hoy tiene que encadenar manualmente ejecución, reducción de scope, inspección y validación de evidencia.

## Objetivo

Agregar un modo explícito que no piense por el agente, pero sí cierre ambigüedad operativa.

## Checklist

- [ ] Diseñar un comando `agent-run` o equivalente.
- [ ] Exponer selección efectiva de pruebas.
- [ ] Exponer si la evidencia del run es confiable o está invalidada por el entorno.
- [ ] Exponer recomendación de siguiente paso en formato estructurado.
- [ ] Mantenerlo deterministicamente basado en reglas, no en heurísticas blandas.

## Ejemplo conceptual

```bash
testkit agent-run \
  --target test/back/tarifa/ \
  --goal "find first real regression" \
  --format json
```

## Respuesta deseable

```json
{
  "goal": "find first real regression",
  "evidence_valid": true,
  "selection": {
    "selected_tests": 16,
    "suite": "back-php"
  },
  "next_action": {
    "kind": "rerun_single_file",
    "target": "test/back/tarifa/integration/tarifa_resolution_precedence_integration.test.php",
    "reason": "first actionable failure"
  }
}
```

## Advertencia

No convertir esto en un pseudo-orquestador opaco. El valor está en la estructura y la transparencia, no en “magia”.
