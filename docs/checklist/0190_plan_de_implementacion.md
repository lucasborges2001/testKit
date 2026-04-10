# 0190 — Plan de implementación sugerido

## Orden recomendado

### Fase 1 — Endurecer verdad operativa

1. `0110_concurrencia_y_aislamiento`
2. `0130_seed_y_bootstrap_state`
3. `0140_first_failure_y_artifacts`

### Fase 2 — Hacer visible y parseable el runner

4. `0120_salida_json_canonica`
5. `0150_comandos_inspect`
6. `0160_warning_hygiene`

### Fase 3 — Mejoras declarativas y modo agente

7. `0170_capabilities_y_hazards`
8. `0180_agent_run_mode`

## Razón del orden

Si primero hacés `agent-run` o una CLI más cómoda sin endurecer aislamiento, seed state y first failure, solo vas a encapsular ambigüedad en una interfaz más linda.

## Riesgos de implementación

- Mezclar compatibilidad legacy con nuevo contrato sin versionado.
- Modelar JSON antes de decidir fuente de verdad del seed state.
- Prometer paralelismo sin demostrar aislamiento real.
- Meter lógica de planificación sin metadatos suficientes.

## Entregables por fase

### Fase 1

- contrato de concurrencia
- seed state canónico
- primer fallo útil visible

### Fase 2

- `--json`
- `inspect`
- warnings clasificados

### Fase 3

- metadatos declarativos
- `agent-run`
