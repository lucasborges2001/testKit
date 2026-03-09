# testkit

Repo reusable de tooling para tests. No contiene tests del dominio del proyecto.

## Modelo

- `testkit/` vive en su propio repo y remoto.
- cada proyecto mantiene sus suites en `<project>/test/**`
- `testkit` apunta al proyecto vía `TESTKIT_PROJECT_ROOT`
- los runners viven solo en `testkit/`
- las seeds del proyecto viven en `<project>/test/seeds/{mysql,pgsql}`

## Layout en contenedor

- proyecto: `/workspace/project`
- testkit: `/workspace/testkit`
- tests del proyecto: `/workspace/project/test`

## Uso básico

Linux/macOS:

```bash
export TESTKIT_PROJECT_ROOT=/ruta/al/proyecto
./bin/testkit doctor
./bin/testkit up -d
./bin/testkit run --rm testkit php runTest.php
./bin/testkit run --rm testkit php runTest.php back
./bin/testkit run --rm testkit php runTest.php front
./bin/testkit run --rm testkit php scripts/seed_router.php mysql
```

PowerShell:

```powershell
$env:TESTKIT_PROJECT_ROOT = 'D:\Proyecto'
.\bin\testkit.ps1 doctor
.\bin\testkit.ps1 up -d
.\bin\testkit.ps1 run --rm testkit php runTest.php back
```

## Contrato mínimo del proyecto

- `<project>/test/back/**`
- `<project>/test/front/**` o `<project>/test/front/tests/**`
- `<project>/test/seeds/mysql/*.sql` y opcionalmente `<project>/test/seeds/pgsql/*.sql`
- `<project>/test/.env.test` o `<project>/.env.test`

## Nota

`testkit` puede ofrecer Docker, ejecución local, profiling, coverage y helpers reutilizables. El proyecto usa solo lo que necesita.
