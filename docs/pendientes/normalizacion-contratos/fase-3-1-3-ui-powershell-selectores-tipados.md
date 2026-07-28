# Fase 3.1.3 — UI PowerShell con selectores tipados

## Estado

Implementado en `testKit`.

## Objetivo

Eliminar de la UI interactiva los targets ambiguos, aliases y filtros públicos basados en variables de entorno, y hacer que el plan ejecute la misma gramática estricta de `RunRequest`.

## Contrato

La UI selecciona exactamente una de estas superficies:

```text
--suite <suite>
--group <group>
--category <category>
```

Un archivo concreto se expresa con `--test <repo-relative>`. El modo listado se expresa con `--list`.

## Invariantes

1. no existe `Target` en el modelo de selección;
2. no existe el alias `back-py`;
3. la UI no emite `TEST_MATCH`, `TEST_CATEGORY` ni `TEST_LIST`;
4. selector y nombre se transportan como argumentos CLI;
5. un archivo solo puede acompañar una suite;
6. stack, scope, jobs y coverage mantienen su contrato actual y quedan fuera de esta subfase;
7. el comando mostrado coincide con los argumentos ejecutados.

## Archivos

```text
M ui/powershell/Testkit.UI.ps1
M ui/powershell/lib/Testkit.UI.Plan.ps1
M tests/powershell/run.ps1
A tests/powershell/test_ui_typed_selectors.ps1
A docs/pendientes/normalizacion-contratos/fase-3-1-3-ui-powershell-selectores-tipados.md
```

## Validación

```powershell
pwsh -NoProfile -NonInteractive -File tests/powershell/test_ui_typed_selectors.ps1
pwsh -NoProfile -NonInteractive -File tests/powershell/run.ps1
```

## Criterio PASS

- catálogos sin aliases;
- exactamente un selector tipado por plan;
- `--test` solo con suite;
- ausencia de `TEST_MATCH`, `TEST_CATEGORY` y `TEST_LIST`;
- comando reproducible igual a la invocación efectiva;
- tests PowerShell verdes.

## No verificado

- ejecución local de PowerShell en este entorno;
- CI Windows;
- workflow completo;
- store, stack y coverage;
- consumidores externos.

## Siguiente commit

Actualizar `.github/workflows/ci.yml` y cerrar el cutover interno de Fase 3.1.