# Browser E2E

TestKit soporta browser E2E reusable con Playwright desde `runners/runBrowserE2e.mjs`.
El host conserva sus casos bajo `test/` y decide cómo integrarlos a sus suites públicas.

## Variables principales

```env
TESTKIT_BROWSER_BASE_URL=http://host.docker.internal:8088
TESTKIT_BROWSER_LOGIN_PATH=/login.php?next=%2Fsuperadmin%2Findex.php
TESTKIT_BROWSER_LOGIN_EMAIL=admin@example.test
TESTKIT_BROWSER_LOGIN_PASSWORD=replace-in-test-env
TESTKIT_BROWSER_EXPECTED_URL=**/superadmin/index.php
TESTKIT_BROWSER_HEADLESS=1
TESTKIT_BROWSER_TRACE=retain-on-failure
```

Las credenciales anteriores son placeholders documentales. Las credenciales reales pertenecen al env/fixture del host y no deben versionarse en documentación.

## Runner reusable

Un adapter del host puede ejecutar directamente el runner reusable cuando ese sea su contrato interno:

```bash
node /workspace/testkit/runners/runBrowserE2e.mjs test/front/browser/e2e/mi_spec.e2e.test.mjs
```

Ese comando describe el runner interno. No sustituye el contrato público de `runTest.php`.

## Ejecución mediante TestKit

Toda invocación de `runTest.php` debe usar un selector tipado:

```text
--suite
--group
--category
```

Si el caso browser está representado por un test descubierto por una suite del host, seleccionar el archivo con `--test`:

```bash
./submodules/Base/testkit/bin/testkit run --rm testkit php runTest.php \
  --suite front-php \
  --test test/front/browser/e2e/browser_wrapper.test.php
```

El path anterior es ilustrativo: debe reemplazarse por el archivo repo-relative real descubierto por el host.

Para un lote declarado:

```bash
./submodules/Base/testkit/bin/testkit run --rm testkit php runTest.php \
  --suite front-php \
  --selection-file .testkit/selection.browser.txt
```

No usar `TEST_MATCH`, `TEST_MATCH_LIST` ni `TEST_MATCH_FILE` como interfaz pública. Esas variables permanecen como bridge interno pendiente de I4 y no deben propagarse a nuevos consumidores.

## Coverage

Browser E2E es runtime/integration. No genera cobertura PHP por sí mismo.

Si `TEST_COVERAGE=1` está activo en una suite PHP que envuelve la ejecución browser, el wrapper puede participar del reporte de la suite, pero no debe atribuir al navegador cobertura PHP inexistente.

## Requisitos de entorno

Un caso browser puede requerir:

- servidor HTTP del host;
- Chromium/Playwright disponible en el runner browser;
- fixtures o credenciales de test;
- rutas y base URL accesibles desde el contenedor.

Una dependencia ausente no debe convertirse en PASS implícito. La suite o adapter del host debe reportar el estado de forma explícita.

## CI

El smoke browser histórico usa un fixture separado bajo `tests/fixtures/browser`. La CI remota está temporalmente deshabilitada en el baseline actual, por lo que no debe inferirse evidencia runtime reciente a partir de esta documentación.

Ver `docs/CI.md` para el estado vigente del gate remoto.