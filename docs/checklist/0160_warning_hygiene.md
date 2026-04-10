# 0160 — Higiene de warnings

## Problema

Warnings repetitivos como contenedores huérfanos degradan la relación señal/ruido y terminan enterrando datos útiles.

## Objetivo

Clasificar, resumir y aislar warnings operativos no bloqueantes.

## Checklist

- [ ] Inventariar warnings frecuentes.
- [ ] Asignarles código estable.
- [ ] Marcar si son bloqueantes o no.
- [ ] Emitirlos agregados al final del run, no ruidosos durante toda la salida.
- [ ] Evaluar auto-remediación segura para algunos casos.
- [ ] Exponerlos en JSON con severidad.

## Estructura sugerida

```json
{
  "warnings": [
    {
      "code": "ORPHAN_CONTAINERS_FOUND",
      "severity": "warn",
      "blocking": false,
      "count": 3,
      "summary": "Hay contenedores huérfanos del proyecto testkit"
    }
  ]
}
```

## Regla

Un warning no bloqueante no debe parecer una falla. Pero tampoco debe quedar oculto.

## Criterio de aceptación

La salida principal se vuelve más legible sin perder trazabilidad operacional.
