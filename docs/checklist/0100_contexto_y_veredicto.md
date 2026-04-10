# 0100 — Contexto y veredicto

## Diagnóstico

El problema principal no es que los agentes usen mal `testkit`.

El problema es que `testkit` todavía obliga a un agente a operar de forma defensiva:

- reruns secuenciales para evitar evidencia inválida
- reducción manual por `TEST_MATCH`
- lectura manual de artefactos secundarios
- inferencia manual del seed/bootstrap state
- parches locales en tests para tolerar contaminación de contexto

## Veredicto

`testkit` hoy se comporta más como una herramienta para humanos expertos que toleran ambigüedad operativa que como un runner con contratos fuertes.

## Objetivo del backlog

Mover `testkit` desde:

- “ejecutor flexible con bastante contexto implícito”

hacia:

- “control plane de pruebas con contratos explícitos y salida machine-friendly”

## Antiobjetivos

No hacer primero:

- prompts para IA
- heurísticas blandas
- documentación cosmética sin cambio de contrato
- autocompletados mágicos que oculten estados reales

## Preguntas de control

Antes de empezar a implementar, contestar por escrito:

1. ¿Se prioriza throughput o confiabilidad de evidencia?
2. ¿La verdad del seed mode vive en el runner o en cada test?
3. ¿Se quiere una CLI amigable para humanos o una interfaz estable para automatización?

Si estas tres preguntas no tienen respuesta explícita, el backlog se va a mezclar y degradar.
