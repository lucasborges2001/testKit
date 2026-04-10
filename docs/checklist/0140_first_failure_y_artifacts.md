# 0140 — First failure y artefactos principales

## Problema

El resumen del runner no siempre muestra el stack o el primer fallo accionable. Eso obliga a navegar manualmente a logs y reportes secundarios.

## Objetivo

Exponer el primer fallo útil como dato de primer nivel.

## Checklist

- [ ] Capturar archivo de test fallado.
- [ ] Capturar nombre del caso o bloque fallado.
- [ ] Capturar tipo de excepción/error.
- [ ] Capturar mensaje resumido.
- [ ] Capturar stack abreviado.
- [ ] Enlazar al artefacto completo.
- [ ] Distinguir setup failure de domain failure.
- [ ] Distinguir evidencia inválida por entorno vs fallo real del sistema bajo prueba.

## Estructura sugerida

```json
{
  "first_failure": {
    "file": "test/back/tarifa/integration/tarifa_resolution_precedence_integration.test.php",
    "case": "grupo + sitio + org: gana grupo",
    "kind": "setup_failure",
    "exception_class": "TarifaException",
    "message": "activar_tarifa_sitio fallo por validacion previa",
    "stack_excerpt": [
      "activaTarifa.php:42",
      "tarifa_resolution_precedence_integration.test.php:44"
    ],
    "artifact_path": ".testkit/reports/runs/.../test.log"
  }
}
```

## Criterio de aceptación

Un desarrollador o agente puede identificar la primera causa probable sin abrir manualmente el árbol de artefactos.
