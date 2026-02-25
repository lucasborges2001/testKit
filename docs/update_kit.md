# Actualizar el TestKit en un repo (sin pisar tests)

Objetivo: que puedas “descargar” una nueva versión del kit y copiarla a un repo **sin overwrites accidentales**.

## Contrato recomendado (para evitar drift)

- **Kit (compartido)**: todo `test/` excepto las carpetas de tests.
- **Proyecto (editable)**: solo los tests del proyecto.

### Estructura

- Tests del proyecto:
  - `test/back/tests/**/*.test.php`
  - `test/front/tests/**/*.test.php`
  - `test/front/tests/**/*.test.mjs`

Runners y tooling (NO tocar por proyecto):

- `test/runTest.php`
- `test/back/runTestBack.php`
- `test/front/runFrontTest.php`
- `test/front/runFrontTest.mjs`
- `test/utils/**`
- `test/docker/**`, `test/compose*.yaml`, `test/bin/**`, `test/scripts/**`

## Procedimiento

1) En tu repo, asegurate de que tus tests estén en `test/back/tests` y `test/front/tests`.

2) Copiá/pegá la nueva carpeta `test/` encima **pero preservando**:

- `test/back/tests/`
- `test/front/tests/`
- `test/.env.test` (si existe)

3) Corré:

```bash
cd test
./bin/testkit doctor
```

## Regla anti-ambigüedad

- `test/.env.test` es preferido.
- `.env.test` en root es soportado.
- No uses otros env “por accidente”: si necesitás env alternativo, seteá `DB_ENV_PATH` explícito.
