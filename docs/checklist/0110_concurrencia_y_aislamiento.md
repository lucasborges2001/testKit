# 0110 — Concurrencia y aislamiento

## Problema

El runner permite o no bloquea con suficiente claridad corridas incompatibles sobre estado compartido. Eso hace que una corrida pueda producir evidencia inválida sin fallar de forma estructural.

## Objetivo

Hacer que una corrida sobre store compartido nunca produzca una ilusión de validez.

## Checklist

- [ ] Identificar todos los modos reales de store/DB: `shared`, `isolated`, `per_worker`, otros.
- [ ] Declarar la política de concurrencia por modo.
- [ ] Bloquear corridas incompatibles con exit code estable.
- [ ] Exponer en salida estructurada si la corrida fue admitida, bloqueada o serializada.
- [ ] Registrar qué recurso compartido fue bloqueado.
- [ ] Emitir mensaje inequívoco cuando una corrida no es evidencia válida por conflicto.
- [ ] Cubrir con tests el caso de doble ejecución concurrente.

## Contrato mínimo sugerido

```json
{
  "store_mode": "shared",
  "concurrency_policy": "exclusive",
  "run_admitted": false,
  "reason": "shared_store_locked",
  "lock_owner_run_id": "20260410T180341Z_04cf9b"
}
```

## Decisiones de diseño a cerrar

- ¿Se rechaza o se encola?
- ¿El lock es por proyecto, por suite, por backend o por recurso de seed?
- ¿Qué grado de paralelismo se considera seguro y verificable?

## Criterio de aceptación

Dos corridas incompatibles lanzadas en paralelo ya no pueden “pisarse en silencio”. Una de estas cosas debe pasar siempre:

- se rechaza una corrida con motivo explícito
- se serializa automáticamente
- se aísla de verdad con recursos independientes
