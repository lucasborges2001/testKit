# Browser E2E

TestKit soporta browser E2E reusable con Playwright desde `runners/runBrowserE2e.mjs`.
El host conserva sus casos bajo `test/` y decide cómo integrarlos a sus suites públicas.

## Variables principales

```env
TESTKIT_BROWSER_BASE_URL=http://host.docker.internal:8088
TESTKIT_BROWSER_TARGET_ID=host-local
TESTKIT_BROWSER_ACTION_MODE=observe_only
TESTKIT_BROWSER_TLS_POLICY=strict
TESTKIT_BROWSER_LOGIN_PATH=/login.php?next=%2Fsuperadmin%2Findex.php
TESTKIT_BROWSER_LOGIN_EMAIL=admin@example.test
TESTKIT_BROWSER_LOGIN_PASSWORD=replace-in-test-env
TESTKIT_BROWSER_EXPECTED_URL=**/superadmin/index.php
TESTKIT_BROWSER_HEADLESS=1
TESTKIT_BROWSER_TRACE=retain-on-failure
```

Las credenciales anteriores son placeholders documentales. Las credenciales reales pertenecen al env/fixture del host y no deben versionarse en documentación.

## Seguridad black-box

`TESTKIT_BROWSER_TLS_POLICY` acepta únicamente:

```text
strict
allow_self_signed_for_explicit_target
```

`strict` es el default. La excepción self-signed es opt-in y sólo es válida para un `https://` explícito. Cuando se activa, el runner restringe requests del contexto browser y del `APIRequestContext` expuesto al spec al mismo origin configurado en `TESTKIT_BROWSER_BASE_URL`.

`TESTKIT_BROWSER_ACTION_MODE` acepta:

```text
observe_only
mutating_ui
```

`observe_only` es el default. Los specs black-box deben declarar cualquier acción mutante mediante `testkit.step(..., { mutating: true }, ...)`; en ese modo la acción falla cerrada con `TESTKIT_BROWSER_OBSERVE_ONLY`.

El runner todavía expone `page` por compatibilidad con specs existentes. Por ello, para escenarios industriales o safety-sensitive, clicks/fill/submit mutantes hechos directamente sobre el `page` crudo no se consideran una frontera de seguridad válida. Los nuevos adapters deben usar `testkit.step` y clasificación consumer-owned.

Ejemplo observacional:

```js
export default async function run({ page, testkit }) {
  await testkit.step("load-login", { screenshot: true }, async () => {
    await page.goto("/webvisu/webvisu.htm");
    await page.waitForLoadState("domcontentloaded");
  });
}
```

Ejemplo de sesión autenticada, sólo con `TESTKIT_BROWSER_ACTION_MODE=mutating_ui`:

```js
await testkit.step("login", { mutating: true, sensitive: true }, async () => {
  // fill/click consumer-owned
});
```

`sensitive: true` evita el screenshot automático de fallo de ese step. El consumidor debe marcar así fases que puedan mostrar credenciales, tokens u otros secretos.

## Artifacts

Cada ejecución escribe `browser-run.json` en `TESTKIT_BROWSER_ARTIFACTS_DIR` con:

- logical target id;
- target origin;
- TLS policy;
- action mode;
- status y duración;
- page title y URL sanitizada;
- console errors y network failures sanitizados;
- steps declarados y screenshots no sensibles.

No se persisten headers, cookies, storage state ni payloads de formularios. Query params con nombres secret-like y diagnósticos textuales sensibles se redactan.

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

## Self-test focal

La política reusable no requiere PLC ni browser real:

```bash
node tests/framework/test_browser_blackbox_policy.mjs
```

El lifecycle browser debe verificarse además dentro de la imagen Playwright existente. La ejecución fake/runtime no se considera demostrada por el test de política anterior.

## Coverage

Browser E2E es runtime/integration. No genera cobertura PHP por sí mismo.

Si `TEST_COVERAGE=1` está activo en una suite PHP que envuelve la ejecución browser, el wrapper puede participar del reporte de la suite, pero no debe atribuir al navegador cobertura PHP inexistente.

## Requisitos de entorno

Un caso browser puede requerir:

- servidor HTTP/HTTPS del host;
- Chromium/Playwright disponible en el runner browser;
- fixtures o credenciales de test;
- rutas y base URL accesibles desde el contenedor.

Una dependencia ausente no debe convertirse en PASS implícito. La suite o adapter del host debe reportar el estado de forma explícita.

## CI

El smoke browser histórico usa un fixture separado bajo `tests/fixtures/browser`. La CI remota está temporalmente deshabilitada en el baseline actual, por lo que no debe inferirse evidencia runtime reciente a partir de esta documentación.

Ver `docs/CI.md` para el estado vigente del gate remoto.
