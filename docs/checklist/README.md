# Checklist de mejoras para `testkit` orientadas a confiabilidad operativa y uso por agentes

Este paquete **no implementa código**. Define el backlog documental mínimo para endurecer `testkit` donde hoy más duele:

- concurrencia e aislamiento reales
- salida estructurada para máquinas
- visibilidad explícita del seed/bootstrap state
- surfacing del primer fallo útil
- comandos de inspección
- higiene de warnings
- metadatos de hazards/capabilities por suite o test
- un modo de ejecución orientado a agentes

## Cómo usar esta carpeta

1. Leer `0100_contexto_y_veredicto.md`.
2. Tomar `0110` a `0180` como checklists temáticos.
3. Ejecutar `0190_plan_de_implementacion.md` como orden sugerido.
4. Usar `0200_definition_of_done.md` para cierre real, no cosmético.

## Criterio rector

No optimizar “UX para IA” primero.

Primero hacer que `testkit` sea:

- **determinista**
- **observable**
- **parseable**
- **honesto sobre sus precondiciones**

Después recién discutir ayudas de más alto nivel.
