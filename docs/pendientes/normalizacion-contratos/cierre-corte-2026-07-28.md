# Cierre del corte de normalización contractual

## Estado

Cerrado como corte de implementación en `testKit`.

Este documento no declara completadas todas las fases originalmente propuestas. Congela el trabajo realizado hasta el commit anterior y separa la deuda restante según ownership.

## Baseline del cierre

```text
Repositorio: lucasborges2001/testKit
Rama: agent/testkit-contract-normalization
Commit previo al cierre documental: bef2484529fbfa38e4476399ca96675fbeef6bae
Fecha: 2026-07-28
```

## Implementado en este corte

- inventario contractual y ADR de corte sin compatibilidad;
- extracción de lógica específica de Tarifa del core;
- registro contractual único para suites, grupos y categorías;
- CLI tipada mediante `--suite`, `--group` y `--category`;
- rechazo de targets posicionales, aliases públicos y `TESTKIT_TARGET_*`;
- comandos internos de reporting y agente migrados a `--suite` y `--test`;
- Makefile y wrappers de migración migrados a selectores tipados;
- UI PowerShell migrada de `Target` a selector `{Kind, Key}`;
- self-tests declarados ausentes tratados como fallo en los runners modificados.

## Verificado

- estructura lineal de commits sobre la rama;
- diffs acotados por responsabilidad;
- validaciones focales PHP y Bash ejecutadas en las subfases correspondientes;
- registro y documentación contractual coherentes en las verificaciones realizadas;
- ausencia de cambios en repositorios externos;
- `main` no fue modificado.

## No verificado

- CI completa Linux y Windows sobre el HEAD final;
- ejecución real de PowerShell para la última subfase;
- Docker Desktop en Windows;
- runtime MySQL/Docker completo;
- consumidores externos;
- integración y fijación de SHA desde `Base` o `Pruebas`;
- PR, merge, release o tag.

## Decisión de cierre

El programa original se divide desde este punto en dos pendientes independientes:

1. `pendiente-interno-testkit.md`: trabajo que puede resolverse modificando únicamente `testKit`;
2. `pendiente-integraciones-externas.md`: trabajo que exige cambios o evidencia fuera de `testKit`.

Las fases externas no bloquean este cierre documental y no autorizan modificaciones en otros repositorios.

## Criterio para reabrir implementación

Solo reabrir este corte si se detecta una regresión introducida por los commits ya realizados. Nuevas mejoras contractuales deben ejecutarse desde el pendiente interno, en fases pequeñas y con baseline nuevo.

## Rollback

- revertir el commit o subfase responsable;
- no restaurar aliases ni doble contrato;
- conservar la documentación de evidencia aunque se revierta runtime;
- no modificar consumidores externos como mecanismo de rollback interno.
