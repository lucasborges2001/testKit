# UI interactiva de testkit

## Qué es

Una fachada interactiva en PowerShell para humanos.

No reemplaza el CLI existente. Construye y ejecuta los mismos flujos ya esperados por `testkit`, muestra el comando real antes de correr y pide confirmación explícita.

## Qué no reemplaza

El contrato canónico sigue siendo:

- `./bin/testkit.ps1 doctor --dump`
- `./bin/testkit.ps1 up -d`
- `./bin/testkit.ps1 run --rm testkit php /workspace/testkit/runTest.php ...`
- `./bin/testkit.ps1 run --rm testkit php /workspace/testkit/scripts/report.php`
- `./scripts/seed.ps1`
- `./bin/testkit.ps1 down`

Agentes, CI, scripts y wrappers existentes deben seguir usando el modo no interactivo actual.

## Estructura

- `bin/testkit-ui.ps1`
  - entrypoint fino
- `ui/powershell/Testkit.UI.ps1`
  - orquestación interactiva
- `ui/powershell/lib/Testkit.UI.Console.ps1`
  - helpers de terminal / prompts
- `ui/powershell/lib/Testkit.UI.Plan.ps1`
  - catálogo, validaciones y construcción del plan de ejecución

## Cómo invocarla

Desde la raíz del repo:

```powershell
.\bin\testkit-ui.ps1