# PLC WebVisu black-box — verificación reusable

Fecha: 2026-09-02
Estado: `REUSABLE_RUNTIME_PASS / CONSUMER_REAL_PENDING`

## Corte reusable verificado

La implementación browser black-box reusable queda certificada en:

```text
TestKit source: e5d0366ffc665df0de9ddf6e53f5403a535693bb
Base pin:       5963f4a5fb828b214c69d7fa536ac16b9017a4de
Consumer host:  lucasborges2001/Pruebas
```

Evidencia remota canónica en `Pruebas`:

```text
docs/test-runs/1788379971.md  browser fake + artifact/redaction
docs/test-runs/1788380289.md  screenshot references portables
docs/test-runs/1788380360.md  policy self-test sobre TestKit pinneado
docs/test-runs/1788382554.md  matriz HTTPS/TLS/timeout/cleanup
```

La última matriz se ejecutó sobre `Pruebas` tested SHA `4800f617d89a2299b75984f01d58e8fe390c0764`, con `validation_scope=host`, `docker_mode=daemon`, Base `5963f4a5...` y TestKit `e5d0366...`.

## Gates cerrados

```text
browser Playwright real                     PASS
fixture HTTP local determinista             PASS
browser-run.json sanitizado                 PASS
query secret-like redaction                 PASS
screenshot no sensible persistido           PASS
screenshot reference portable               PASS
strict TLS contra self-signed                EXPECTED FAIL verificado
self-signed opt-in al origin explícito       PASS
timeout controlado                           EXPECTED FAIL verificado
cleanup browser/server/cert/key              PASS
observe_only por default                     PASS por contrato
same-origin al habilitar self-signed         PASS por contrato
```

Los expected-failure TLS/timeout sólo son aceptados cuando `runBrowserE2e.mjs` devuelve exit `1`; un error operativo diferente hace fallar la matriz. El validador consumer-owned inspecciona además los artifacts resultantes.

## Límite vigente

`page` crudo continúa expuesto por compatibilidad. `observe_only` es una frontera verificable para acciones declaradas con `testkit.step`, no un sandbox completo frente a specs legacy que ejecuten clicks/fill directamente. Los consumidores safety-sensitive deben mantener clasificación de acciones propia y usar steps declarados.

## No es deuda TestKit

La conexión con PLC/WebVisu real, selectors del consumidor, credenciales, autenticación, mapas de señales y autorización física pertenecen al host/consumer. Un piloto real no es criterio de aceptación de esta primitive reusable.

Si una ejecución consumer real demuestra un defecto reproducible de lifecycle, TLS, sanitización o artifacts en TestKit, abrir un pendiente nuevo y específico con esa evidencia.
