# CI de testKit

## Estado actual

La CI remota está **temporalmente deshabilitada** en el baseline:

```text
main: 8fd6cca8b91167c57bd4189e81365e5e4d34e3da
motivo documentado: presupuesto de GitHub Actions no disponible
```

El workflow vigente `.github/workflows/ci.yml` conserva únicamente `workflow_dispatch` y un job con `if: false`.

Por lo tanto:

- no existe ejecución automática en push o pull request;
- no hay evidencia CI nueva para el HEAD actual;
- no se debe declarar CI verde por el solo hecho de que el YAML anterior haya sido validado históricamente;
- `BLOCKED_CI_BUDGET` no equivale a `PASS`.

## Baseline contractual a restaurar

Antes de ser deshabilitado, el workflow normalizado separaba estas superficies:

1. sintaxis/estructura Linux;
2. self-tests PHP del framework;
3. contratos PowerShell en Windows;
4. runtime MySQL sobre un host fixture;
5. smoke del browser runner sobre un host fixture no-store.

La deshabilitación por presupuesto no elimina esos contratos; únicamente impide usarlos como evidencia remota actual.

## Contrato de selectores

Toda invocación de `runTest.php` en CI debe declarar exactamente uno de:

```text
--suite
--group
--category
```

No se admiten targets posicionales, aliases públicos, `TEST_TARGET` ni `TESTKIT_TARGET_*`.

Ejemplos:

```bash
php runTest.php --suite back-php
php runTest.php --group all --list
php runTest.php --category smoke
```

## Gate local mínimo mientras CI está bloqueada

La ejecución local no reemplaza la CI remota, pero permite clasificar fallos antes de reactivarla.

### PHP y contratos

```bash
php tests/framework/test_ci_typed_selectors.php
php tests/framework/test_store_driver_contract.php
php tests/framework/run.php
```

### Sintaxis PHP

```bash
find . -type f -name '*.php' \
  -not -path './vendor/*' \
  -print0 | xargs -0 -r -n1 php -l
```

### Bash

```bash
find bin scripts lib -type f \
  \( -name '*.sh' -o -name 'testkit' \) \
  -print0 | xargs -0 -r -n1 bash -n
```

### PowerShell

Desde un host con PowerShell 7:

```powershell
pwsh -NoProfile -NonInteractive -File tests\powershell\run.ps1
```

Un PASS local debe reportarse como local; no como CI PASS.

## Superficies que debe recuperar el workflow completo

Cuando exista presupuesto y se autorice restaurar el workflow, el gate remoto debería volver a demostrar al menos:

```text
static
windows-static
framework-self-tests
runtime-mysql
browser-runner-smoke
```

La restauración debe hacerse desde historia Git o mediante un cambio explícito y revisable. No reconstruir de memoria el workflow perdido.

## Runtime MySQL

El fixture contractual conocido es:

```text
tests/fixtures/runtime-mysql-host
```

Una validación real debe demostrar:

```text
TEST_STORE_DRIVER=mysql
-> doctor
-> stack MySQL
-> seed
-> discovery tipado
-> ejecución
-> inspect latest
-> teardown
```

No usar `migration-contract` como preflight genérico: requiere snapshot resoluble y tiene restricciones propias.

## Browser runner

El fixture contractual conocido es:

```text
tests/fixtures/browser
```

Debe ejecutarse como proyecto no-store:

```env
TEST_STORE_DRIVER=none
TEST_STORE_PROVISION=external
```

El smoke debe conservar artifacts diagnósticos del fixture y no usar la raíz de `testKit` como proyecto host implícito.

## Windows

Los tests estáticos/PowerShell no prueban por sí solos:

- Docker Desktop real;
- mounts NTFS/WSL2 en todos los casos;
- MySQL runtime sobre Windows;
- terminación nativa completa de procesos PHP en Windows.

La deuda de timeout/terminación de `ProcessRunner` está separada en:

```text
docs/pendientes/processrunner-timeout-windows.md
```

## Reactivación

La reactivación de Actions es una acción remota separada y requiere autorización explícita.

Cuando haya presupuesto y se haya restaurado el workflow completo, ejecutar un run nuevo y observar sus jobs antes de cerrar verificaciones.

No usar runs históricos como evidencia del HEAD nuevo.

## Criterio de cierre de una verificación CI

PASS requiere:

- workflow completo habilitado;
- run asociado al SHA que se pretende validar;
- jobs contractuales finalizados con éxito;
- artifacts/logs suficientes para distinguir fallos del cambio y fallos del entorno;
- ausencia de `BLOCKED` presentado como `PASS`.
