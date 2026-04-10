# 0200 — Definition of Done

## No cerrar esta iniciativa si solo pasa esto

- [ ] hay más documentación
- [ ] la CLI imprime mensajes más lindos
- [ ] existe un JSON incompleto pero sigue obligando a abrir logs
- [ ] hay un `agent-run` que no sabe distinguir evidencia inválida

## Cerrar recién cuando pasa esto

### Concurrencia
- [ ] una corrida incompatible no puede contaminar silenciosamente otra
- [ ] el runner informa si rechazó, serializó o aisló

### Seed/bootstrap
- [ ] el seed mode real del run es visible y estable
- [ ] los tests dejan de parsear env vars para entender bootstrap

### Diagnóstico
- [ ] el primer fallo útil sale como dato de primer nivel
- [ ] setup failure y domain failure quedan diferenciados

### Observabilidad
- [ ] existe una salida JSON canónica versionada
- [ ] `inspect` cubre latest, failure, seed-state y concurrency

### Ruido operacional
- [ ] los warnings frecuentes tienen código, severidad y clasificación
- [ ] la salida principal mejora señal/ruido

### Uso por agentes
- [ ] un agente puede decidir el siguiente paso sin abrir artefactos secundarios en la mayoría de los casos
- [ ] `agent-run` no oculta estados reales ni inventa heurísticas opacas

## Métrica práctica sugerida

Tomar 10 sesiones reales de depuración y medir:

- cuántas veces hubo que abrir artefactos manuales
- cuántas veces hubo evidencia inválida por concurrencia
- cuántas veces el seed mode tuvo que inferirse a mano
- cuántas veces el primer fallo accionable no fue visible en el primer resumen

Si esas métricas no bajan de forma clara, el cambio no está terminado.
