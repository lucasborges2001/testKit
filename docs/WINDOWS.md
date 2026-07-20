# testKit en Windows

Este documento describe la ruta operativa soportada para usar `testkit` desde
Windows, con PowerShell 7 como wrapper y Docker Desktop (Linux containers)
como runtime. Léelo junto con [`docs/USO.md`](USO.md),
[`docs/TROUBLESHOOTING.md`](TROUBLESHOOTING.md) y
[`SUPPORT_MATRIX.md`](../SUPPORT_MATRIX.md).

## Requisitos

- Windows 11 (o Windows 10 con soporte WSL2 vigente).
- PowerShell 7 (`pwsh`). El wrapper (`bin/testkit.ps1`) está pensado para
  PowerShell 7; no se prueba contra PowerShell 5.1.
- Docker Desktop, con el backend WSL2 habilitado y contenedores **Linux**
  (no Windows containers).
- Un checkout de `testkit` y un checkout del proyecto host, ambos accesibles
  desde el filesystem que Docker Desktop puede montar.

## Rutas soportadas

### Ruta primaria: checkout NTFS + Docker Desktop/WSL2

```text
Windows 11 → PowerShell 7 → Docker Desktop (backend WSL2) → contenedor Linux de testkit
```

El proyecto y `testkit` pueden vivir en el mismo drive o en drives distintos
(`C:\dev\Pruebas` y `D:\dev\testkit`, por ejemplo). Ambos casos están
cubiertos por `Test-TestkitPathUnderRoot` en `lib/powershell/Env.ps1`.

### Ruta alternativa: checkout dentro de WSL2

Para repos grandes o con muchos bind mounts, hacer el checkout dentro del
filesystem de una distribución WSL2 y operar desde Bash suele rendir mejor
que un checkout NTFS. Esa ruta usa el wrapper Bash (`bin/testkit`), no
`testkit.ps1`; el resto de este documento asume la ruta primaria (PowerShell).

### Fuera de alcance

- Windows containers.
- PowerShell 5.1 como objetivo contractual (puede funcionar, pero no está
  cubierto por `tests/powershell/`).
- Paths de red (UNC) como `\\server\share\...`.
- Cambiar la Execution Policy automáticamente — ver más abajo.

## Variables de entorno clave

```powershell
$env:TESTKIT_PROJECT_ROOT = 'D:\dev\Pruebas'   # repo bajo prueba
$env:TESTKIT_ROOT = 'D:\dev\testkit'           # checkout de testkit (opcional; por defecto, el padre de bin/)
$env:TESTKIT_MODE = 'agent'                    # opcional: perfil determinista para agentes, ver AGENTS.md
```

`testkit.ps1` busca el env de tests en `<project>/test/.env.test` primero, y
`<project>/.env.test` como fallback. `TESTKIT_ENV_FILE` puede forzar una ruta
explícita si ninguna de las dos aplica.

## Execution Policy

`testkit.ps1` no cambia la Execution Policy del sistema ni la de la sesión.
Si PowerShell bloquea la ejecución del script, es un diagnóstico a resolver
por quien administra la máquina (política de grupo, `Set-ExecutionPolicy`
a nivel de usuario), no algo que este proyecto automatice. Para diagnosticar:

```powershell
Get-ExecutionPolicy -List
```

## Quick start sin store

```powershell
$env:TESTKIT_PROJECT_ROOT = 'D:\dev\Pruebas'
$env:TEST_STORE_DRIVER = 'none'

.\bin\testkit.ps1 doctor --readonly --compact
.\bin\testkit.ps1 run --rm testkit php runTest.php --list
.\bin\testkit.ps1 run --rm testkit php runTest.php back-php
.\bin\testkit.ps1 inspect latest
```

## Quick start MySQL

```powershell
$env:TESTKIT_PROJECT_ROOT = 'D:\dev\Pruebas'

.\bin\testkit.ps1 doctor --compact
.\bin\testkit.ps1 doctor --full migration-contract
.\bin\testkit.ps1 up -d
.\bin\testkit.ps1 run --rm testkit php runTest.php back-php
.\bin\testkit.ps1 inspect latest
.\bin\testkit.ps1 down -vc
```

## `doctor --readonly`

`doctor` por defecto crea `<project>/test/` si no existe y escribe/borra una
sonda de escritura (`.doctor_write_probe`) para confirmar permisos. Cuando
sólo se quiere diagnosticar sin tocar el repo del proyecto (por ejemplo,
antes de que un agente decida qué hacer), usar:

```powershell
.\bin\testkit.ps1 doctor --readonly --compact
```

En modo `--readonly`, doctor no crea `test/`, no escribe ninguna sonda, y
reporta ese chequeo puntual como `UNKNOWN` (`TEST_DIR_WRITE_NOT_PROBED`) en
vez de `PASS`/`FAIL`. El resto de los chequeos (env detectado, `TESTKIT_ROOT`,
`TESTKIT_PROJECT_ROOT`, containment, Docker en PATH, credenciales visibles)
se ejecutan igual — sólo el chequeo de escritura se omite.

```powershell
git status --short
.\bin\testkit.ps1 doctor --readonly --compact
git status --short   # debe quedar idéntico al anterior
```

## Troubleshooting

### El repo no se monta / Docker no ve los archivos

Confirmá que el drive donde vive el checkout está compartido con Docker
Desktop (Settings → Resources → File sharing, si usás el backend Hyper-V; con
WSL2 esto normalmente no aplica porque Docker Desktop monta directo desde la
distro). Si `TESTKIT_ROOT`/`TESTKIT_PROJECT_ROOT` no son un repo completo,
`doctor` lo va a marcar con `TESTKIT_ROOT_INCOMPLETE` o
`PROJECT_ROOT_MISSING`.

### Puertos ocupados

Si `up -d` falla por un puerto en uso (MySQL 3306, Redis 6379, etc.), otro
proceso local (otra instancia de MySQL, otro stack de Docker) probablemente
ya lo está usando. Bajalo con `.\bin\testkit.ps1 down -vc` antes de reintentar,
o cambiá el mapeo de puertos en el compose override que corresponda.

### El env de tests "quedó afuera del repo montado"

`doctor` (y el wrapper antes de correr cualquier comando runtime) valida que
el env de tests esté dentro de `TESTKIT_PROJECT_ROOT`. Si ves
`ENV_OUTSIDE_PROJECT` o el wrapper corta con "El env de tests quedó fuera del
repo montado", movés el archivo a `<project>/test/.env.test` o
`<project>/.env.test`, no fuera del checkout.

### CRLF inesperado en un script

Este repo fija `eol=lf` para `.sh`, `bin/testkit`, `.ps1`/`.psm1` y el resto
del código fuente vía `.gitattributes`. Si tu editor o tu configuración local
de Git reintroduce CRLF y algo se rompe dentro de un contenedor Linux, revisá
`core.autocrlf` y volvé a hacer checkout del archivo afectado.

## Limpieza

Igual que en Linux/macOS, ver [`docs/CLEANUP.md`](CLEANUP.md):

```powershell
.\bin\testkit.ps1 cleanup reports --max-runs=10 --dry-run
.\bin\testkit.ps1 cleanup reports --max-runs=10 --apply
```

## Rutas soportadas / no soportadas

| Caso | Soporte |
|---|---|
| PowerShell 7 + Docker Desktop (backend WSL2) | Soportado, ruta primaria |
| Checkout NTFS con path con espacios | Soportado |
| Proyecto y `testkit` en drives distintos | Soportado |
| Path vecino con prefijo común (`Pruebas` vs `Pruebas-otro`) | Rechazado explícitamente por containment |
| Checkout dentro de una distro WSL2, operado por Bash | Soportado como ruta alternativa (usa `bin/testkit`, no este documento) |
| Windows containers | No soportado |
| PowerShell 5.1 | No cubierto por los tests de este repo |
| Paths UNC (`\\server\share\...`) | No soportado |
