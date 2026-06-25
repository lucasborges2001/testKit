# Browser E2E

Testkit soporta browser E2E reusable con Playwright desde `runners/runBrowserE2e.mjs`.
El host mantiene sus casos bajo `test/` y los invoca como tests normales del runner actual.

Variables principales:

```env
TESTKIT_BROWSER_BASE_URL=http://host.docker.internal:8088
TESTKIT_BROWSER_LOGIN_PATH=/login.php?next=%2Fsuperadmin%2Findex.php
TESTKIT_BROWSER_LOGIN_EMAIL=admin@pruebas.local
TESTKIT_BROWSER_LOGIN_PASSWORD=pruebas-admin-2026
TESTKIT_BROWSER_EXPECTED_URL=**/superadmin/index.php
TESTKIT_BROWSER_HEADLESS=1
TESTKIT_BROWSER_TRACE=retain-on-failure
```

Un wrapper PHP del host puede ejecutar:

```bash
node /workspace/testkit/runners/runBrowserE2e.mjs test/integration/browser/mi_spec.mjs
```

El browser E2E es runtime/integration. No genera cobertura PHP por si mismo; si
`TEST_COVERAGE=1` esta activo, el wrapper PHP puede participar del reporte sin
atribuir cobertura del navegador.

Recomendacion de ejecucion:

```bash
./submodules/Base/testkit/bin/testkit run --rm -e TEST_MATCH=browser testkit php runTest.php all
```

El host puede hacer que el wrapper salte en corridas no filtradas para no volver
obligatorio el navegador en smokes rapidos.
