# Verificación — CI con selectores tipados

## Estado

```text
ESTADO: PENDIENTE
CLASIFICACION: VERIFICACION_CI_RUNTIME
IMPLEMENTACION_BASE: EXISTENTE
PRODUCCION_AUTORIZADA: NO
```

## Implementación existente

I1 fue implementado sobre `main` a partir del baseline:

```text
0c4c5aeca37af43a1e7a045cc429fe9e71cdfcb3
```

El corte incluye:

- `.github/workflows/ci.yml` con `runTest.php --group all --list` para discovery;
- `.github/workflows/ci.yml` con `runTest.php --group all` para runtime;
- `doctor --full --suite migration-contract`;
- `tests/framework/test_ci_typed_selectors.php` como gate anti-regresión;
- registro del gate en `tests/framework/run.php`;
- `docs/CI.md` alineado con los comandos ejecutables.

No se considera verificado únicamente por existir estos archivos.

## Entorno requerido

Para validación local estática:

- checkout limpio de `lucasborges2001/testKit`;
- PHP 8.4 recomendado;
- Bash;
- PowerShell 7 para la suite Windows.

Para validar el runtime equivalente a CI:

- Docker Engine/Compose;
- MySQL mediante el stack de testKit;
- entorno descartable;
- `.env.test` derivado de `.env.test.example`;
- sin credenciales productivas.

## Baseline a registrar

```bash
cd /ruta/a/testKit

git branch --show-current
git rev-parse HEAD
git status --short
git log --oneline -8
```

Esperado:

```text
branch: main
working tree: limpio
```

## Gate 1 — contrato estático de CI

```bash
php -l tests/framework/test_ci_typed_selectors.php
php tests/framework/test_ci_typed_selectors.php
```

Debe terminar con:

```text
OK CI typed selectors
```

Además:

```bash
grep -nE 'runTest\.php (all|--list)|doctor --full migration-contract|doctor --target=|TEST_TARGET=|TESTKIT_TARGET_' .github/workflows/ci.yml
```

Resultado esperado: sin matches.

## Gate 2 — self-tests PHP

```bash
php tests/framework/run.php
```

Criterio:

- `CI typed selector contract` aparece en PASS;
- ningún test registrado está ausente;
- cualquier fallo restante debe clasificarse como introducido o baseline antes de cerrar esta verificación.

## Gate 3 — sintaxis Bash/PHP

```bash
find . -type f -name '*.php' \
  -not -path './vendor/*' \
  -print0 | xargs -0 -r -n1 php -l

find bin scripts lib -type f \
  \( -name '*.sh' -o -name 'testkit' \) \
  -print0 | xargs -0 -r -n1 bash -n
```

## Gate 4 — PowerShell

Desde PowerShell 7:

```powershell
pwsh -NoProfile -NonInteractive -File tests/powershell/run.ps1
php tests/framework/run.php
```

No declarar runtime Windows validado con este gate: valida contratos y scripts, no Docker Desktop real.

## Gate 5 — runtime MySQL tipado

Preparación en un checkout descartable:

```bash
cp .env.test.example .env.test
sed -i 's/^TESTKIT_STACK=.*/TESTKIT_STACK=mysql/' .env.test
sed -i 's/^DB_DRIVER=.*/DB_DRIVER=mysql/' .env.test
sed -i 's/^TEST_DB_STRATEGY=.*/TEST_DB_STRATEGY=shared/' .env.test
sed -i 's/^TEST_JOBS=.*/TEST_JOBS=1/' .env.test
```

Ejecución:

```bash
TESTKIT_STACK=mysql ./bin/testkit doctor --compact
TESTKIT_STACK=mysql ./bin/testkit doctor --full --suite migration-contract
TESTKIT_STACK=mysql ./bin/testkit up -d
TESTKIT_STACK=mysql ./bin/testkit ps
TESTKIT_STACK=mysql ./scripts/seed.sh
TESTKIT_STACK=mysql ./bin/testkit run --rm testkit php runTest.php --group all --list
TESTKIT_STACK=mysql ./bin/testkit run --rm testkit php runTest.php --group all
TESTKIT_STACK=mysql ./bin/testkit inspect latest
```

Teardown:

```bash
TESTKIT_STACK=mysql ./bin/testkit down -vc
```

## Gate 6 — GitHub Actions real

Revisar el workflow `CI` ejecutado sobre el SHA candidato.

Jobs esperados:

```text
static
windows-static
framework-self-tests
runtime-mysql
browser-runner-smoke
```

No declarar I1 cerrado si un job requerido sigue rojo sin clasificar.

## Resultado esperado

- no quedan targets posicionales en `.github/workflows/ci.yml`;
- discovery usa `--group all --list`;
- runtime usa `--group all`;
- `migration-contract` se expresa como `--suite migration-contract`;
- el gate anti-regresión se ejecuta dentro de `tests/framework/run.php`;
- PHP/Bash/PowerShell self-tests pasan o sus fallos preexistentes quedan clasificados;
- GitHub Actions real confirma el workflow sobre el SHA auditado.

## Evidencia a conservar durante la verificación

- rama y SHA;
- `git status --short` inicial y final;
- salida de `test_ci_typed_selectors.php`;
- resumen de `tests/framework/run.php`;
- salida PowerShell;
- resultado exacto de cada job de Actions;
- artifacts de `runtime-mysql` si falla;
- `git status --short` después del teardown.

No conservar `.env.test`, secretos ni artifacts sensibles en Git.

## Criterio PASS/FAIL

### PASS

- gate estático PASS;
- self-tests relevantes PASS;
- comandos runtime tipados ejecutan sin volver a aliases;
- Actions real no presenta fallos introducidos por I1.

### FAIL

- reaparece un target posicional;
- `doctor --suite migration-contract` no es aceptado;
- discovery/runtime no pueden ejecutarse mediante selectores tipados;
- el gate no detecta una regresión deliberada de la superficie legacy;
- CI falla por un cambio introducido en este corte.

### BLOCKED

Usar `BLOCKED` si falta Docker, PowerShell 7, acceso a Actions o capacidad de preparar un entorno descartable.

## Fuera de este gate

No valida:

- I2 store explícito;
- I3 stack estricto;
- I4 eliminación de bridges internos de selección;
- I5 coverage único;
- protocolo de agentes v2;
- runtime Windows/Docker Desktop real;
- consumidores externos;
- executor de runtime externo.

## Acción después de PASS

1. actualizar documentación estable si la ejecución mostró diferencias;
2. confirmar que no se generó nueva deuda de implementación;
3. borrar `docs/verificaciones/01_ci_selectores_tipados.md`;
4. continuar con el siguiente pendiente interno real.
