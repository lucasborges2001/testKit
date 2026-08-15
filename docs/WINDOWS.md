# testKit en Windows

Este documento describe la ruta operativa prevista para usar `testkit` desde Windows, con PowerShell 7 como wrapper y Docker Desktop (Linux containers) como runtime. Léelo junto con [`docs/USO.md`](USO.md), [`docs/TROUBLESHOOTING.md`](TROUBLESHOOTING.md), [`docs/CI.md`](CI.md) y [`SUPPORT_MATRIX.md`](../SUPPORT_MATRIX.md).

La superficie PowerShell está cubierta por contratos y self-tests. La ejecución real con Docker Desktop/MySQL sobre un host Windows no tiene evidencia remota vigente mientras la CI de `testKit` permanece deshabilitada. No confundir soporte contractual del wrapper con validación runtime actual.

## Requisitos

- Windows 11 (o Windows 10 con soporte WSL2 vigente).
- PowerShell 7 (`pwsh`). El wrapper `bin/testkit.ps1` no tiene PowerShell 5.1 como objetivo contractual.
- Docker Desktop con backend WSL2 y contenedores Linux para los comandos runtime.
- Un checkout de `testkit` y un checkout del proyecto host accesibles desde Docker Desktop.

## Contrato público vigente

Antes de ejecutar TestKit se define el proyecto host:

```powershell
$env:TESTKIT_PROJECT_ROOT = 'D:\dev\Pruebas'
```

El host debe proveer su env de test en una de estas rutas:

```text
<project>\test\.env.test
<project>\.env.test
```

`TESTKIT_ENV_FILE` puede seleccionar explícitamente uno de esos archivos cuando sea necesario.

Los comandos de test usan exactamente un selector tipado:

```text
--suite
--group
--category
```

No se soportan targets posicionales, `TEST_TARGET`, `TESTKIT_TARGET_*` ni `doctor --target`.

## Rutas operativas

### Ruta objetivo: checkout NTFS + Docker Desktop/WSL2

```text
Windows 11 → PowerShell 7 → Docker Desktop (backend WSL2) → contenedor Linux de testkit
```

El proyecto y `testkit` pueden vivir en el mismo drive o en drives distintos. La validación de paths está cubierta por `Test-TestkitPathUnderRoot` en `lib/powershell/Env.ps1`.

Esto no demuestra por sí solo que Docker Desktop, bind mounts y stores reales funcionen en todos los hosts Windows. Esa evidencia runtime debe obtenerse en una máquina Windows real o runner equivalente y registrarse por separado.

### Ruta alternativa: checkout dentro de WSL2

Para repos grandes o con muchos bind mounts, un checkout dentro del filesystem de WSL2 suele rendir mejor. Esa ruta usa el wrapper Bash `bin/testkit`, no `testkit.ps1`.

### Fuera de alcance

- Windows containers.
- PowerShell 5.1 como objetivo contractual.
- Paths UNC (`\\server\share\...`).
- Cambiar automáticamente la Execution Policy.

## Variables de entorno clave

```powershell
$env:TESTKIT_PROJECT_ROOT = 'D:\dev\Pruebas'
$env:TESTKIT_ROOT = 'D:\dev\testkit'        # opcional
$env:TESTKIT_MODE = 'agent'                  # opcional
```

El store estructural se selecciona únicamente con:

```text
TEST_STORE_DRIVER=mysql
TEST_STORE_DRIVER=pgsql
TEST_STORE_DRIVER=none
```

Los valores son exactos y case-sensitive. `DB_DRIVER`, `TEST_DB_DRIVER`, DSN, nombres de DB y `TESTKIT_STACK` no seleccionan store.

## Quick start sin store

```powershell
$env:TESTKIT_PROJECT_ROOT = 'D:\dev\Pruebas'
$env:TEST_STORE_DRIVER = 'none'
$env:TEST_STORE_PROVISION = 'external'

.\bin\testkit.ps1 doctor --readonly --suite back-php --compact
.\bin\testkit.ps1 run --rm testkit php runTest.php --suite back-php --list
.\bin\testkit.ps1 run --rm testkit php runTest.php --suite back-php
.\bin\testkit.ps1 inspect latest
```

## Quick start MySQL

El env del proyecto debe declarar `TEST_STORE_DRIVER=mysql` y las credenciales requeridas por su estrategia de provisioning.

```powershell
$env:TESTKIT_PROJECT_ROOT = 'D:\dev\Pruebas'

.\bin\testkit.ps1 doctor --compact
.\bin\testkit.ps1 doctor --full --suite back-php
.\bin\testkit.ps1 up -d
.\bin\testkit.ps1 run --rm testkit php runTest.php --suite back-php
.\bin\testkit.ps1 inspect latest
.\bin\testkit.ps1 down -v --remove-orphans
```

`migration-contract` no debe usarse como preflight genérico: exige una fuente snapshot resoluble. Debe ejecutarse únicamente con un fixture/proyecto que declare ese contrato.

## `doctor --readonly`

`doctor` por defecto puede crear `<project>/test/` y usar una sonda de escritura. Cuando sólo se quiere diagnosticar sin tocar el host:

```powershell
.\bin\testkit.ps1 doctor --readonly --suite back-php --compact
```

En `--readonly` no se crea `test/` ni se escribe la sonda; el chequeo de escritura queda `UNKNOWN` en vez de inventar un PASS.

```powershell
git status --short
.\bin\testkit.ps1 doctor --readonly --suite back-php --compact
git status --short
```

El estado Git debe ser idéntico antes y después.

## Estado de evidencia Windows

La CI remota de `testKit` está temporalmente deshabilitada por presupuesto. Por lo tanto no existe un job Windows vigente que pueda usarse como evidencia nueva del HEAD actual.

El workflow completo anterior incluía un gate `windows-static` sobre `windows-2025` que cubría:

1. existencia de archivos PowerShell críticos;
2. parseo de `bin`, `lib`, `ui`, `scripts` y `tests/powershell`;
3. detección de CRLF en scripts Linux críticos;
4. `tests/powershell/run.ps1`;
5. self-tests PHP cuando correspondía al corte validado.

El harness PowerShell incluye un gate específico de `TEST_STORE_DRIVER` sobre `seed.ps1` y `db_clean.ps1`. Sin Docker valida:

- ausencia → `TEST_STORE_DRIVER_REQUIRED`, exit `2`;
- valor inválido → `TEST_STORE_DRIVER_INVALID`, exit `2`;
- `TEST_STORE_DRIVER=none` exportado prevalece sobre un valor distinto del archivo env y termina sin invocar runtime.

Ese tipo de gate estático no demuestra Docker Desktop/MySQL sobre Windows. La evidencia runtime real sigue pendiente y debe registrarse sin convertir parseo o self-tests en un falso PASS de runtime.

La deuda conocida de terminación/timeout de procesos nativos PHP en Windows está separada en `docs/pendientes/processrunner-timeout-windows.md`.

## Execution Policy

`testkit.ps1` no modifica la Execution Policy. Si PowerShell bloquea scripts, diagnosticar la política vigente:

```powershell
Get-ExecutionPolicy -List
```

La corrección corresponde al usuario/administrador de la máquina, no a TestKit.

## Troubleshooting

### El repo no se monta / Docker no ve los archivos

Confirmá que Docker Desktop puede montar los drives de los checkouts. Si `TESTKIT_ROOT` o `TESTKIT_PROJECT_ROOT` no son válidos, `doctor` debe reportarlo antes del runtime.

### Puertos ocupados

Si `up -d` falla por un puerto en uso, eliminá primero el stack anterior:

```powershell
.\bin\testkit.ps1 down -v --remove-orphans
```

### Env fuera del proyecto montado

El env de test debe permanecer dentro de `TESTKIT_PROJECT_ROOT`. Si aparece `ENV_OUTSIDE_PROJECT`, corregí la ruta; no uses un archivo externo al checkout del host.

### CRLF inesperado

El repo fija EOL para scripts mediante `.gitattributes`. Si un checkout local reintroduce CRLF, revisar `core.autocrlf` y volver a materializar el archivo afectado.

## Limpieza

Ver [`docs/CLEANUP.md`](CLEANUP.md):

```powershell
.\bin\testkit.ps1 cleanup reports --max-runs=10 --dry-run
.\bin\testkit.ps1 cleanup reports --max-runs=10 --apply
```

## Matriz resumida

| Caso | Estado |
|---|---|
| PowerShell 7: wrapper, parseo y contratos estáticos | Cubierto contractualmente |
| Checkout NTFS con espacios | Cubierto por lógica/tests de paths; runtime real no revalidado en este corte |
| Proyecto y `testkit` en drives distintos | Cubierto por lógica/tests de paths; runtime real no revalidado en este corte |
| Docker Desktop/WSL2 + containers Linux | Ruta runtime objetivo; evidencia Windows real pendiente |
| Checkout dentro de WSL2 operado por Bash | Ruta alternativa |
| Gate `windows-static` histórico | Sin evidencia remota nueva mientras CI está deshabilitada |
| Windows containers | No soportado |
| PowerShell 5.1 | No cubierto |
| Paths UNC | No soportado |
