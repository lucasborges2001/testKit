# PLC WebVisu black-box reusable

Fecha: 2026-09-02
Estado: `IMPLEMENTED / RUNTIME_PARTIAL_PASS`
Prioridad: P1

## Evidencia

TestKit ya posee browser runner/Playwright, reporting/artifacts, probes PLC read-only y Functional HIL separados. Un consumidor real, CentroLogistico, dispone de un WAGO PFC200 con WebVisu accesible y necesita automatizar pruebas black-box sin introducir código de test dentro del PLC.

La auditoría del corte `188236fe802fdfb7265400cdb0beafbed441c223` confirmó que `runners/runBrowserE2e.mjs` reutilizaba Playwright pero aplicaba `ignoreHTTPSErrors: true` incondicionalmente. Eso impedía un default TLS estricto y justificó extender el runner existente, no crear un segundo stack.

## Implementación del corte

Se agregó sobre el runner browser existente:

1. `TESTKIT_BROWSER_TLS_POLICY=strict|allow_self_signed_for_explicit_target`, con `strict` por defecto.
2. Restricción al origin explícito cuando se habilita self-signed, tanto para navegación/requests browser como para el `APIRequestContext` expuesto al spec.
3. `TESTKIT_BROWSER_ACTION_MODE=observe_only|mutating_ui`, con `observe_only` por defecto.
4. `testkit.step()` con declaración `mutating` y `sensitive` para que los nuevos adapters puedan fallar cerrado y evitar screenshots automáticos de fases secret-bearing.
5. `browser-run.json` sanitizado con target lógico, origin, TLS mode, action mode, status, duración, URL/título, console errors, network failures y steps.
6. Redacción básica de URLs/query params y diagnósticos secret-like.
7. Self-test focal `tests/framework/test_browser_blackbox_policy.mjs` sin PLC real.

## Evidencia runtime real

El host `lucasborges2001/Pruebas` ejecutó el adapter fake de CentroLogistico mediante el agente remoto sobre `ubuntudev` usando exactamente:

```text
Pruebas tested_sha=254cfbe764e26e87602f8388ce5da8710ea34f5a
Base=51f7b4c71973fbd1386c6c655ffcb0b56a626324
Base/TestKit=dd182a4be52b761241237a4ee5396bda3f4f3d91
report=Pruebas/docs/test-runs/1788379237.md
Pipeline=PASS
Evidence=PASS
Product=PASS
centrologistico_blackbox_fake=PASS
```

La suite levantó un fixture HTTP local dentro del contenedor browser, ejecutó `runBrowserE2e.mjs` con `TESTKIT_BROWSER_ACTION_MODE=observe_only` y `TESTKIT_BROWSER_TLS_POLICY=strict`, y terminó PASS sin acceso al PLC ni credenciales.

Esto certifica de punta a punta:

```text
fixture browser local determinista = PASS
adapter fake de host = PASS
runner Playwright real = PASS
integración Base -> TestKit -> browser runner = PASS
```

La evidencia remota publicada no incorpora el contenido de `.testkit/artifacts/.../browser-run.json`; por tanto todavía no permite cerrar los checks de artifact/screenshot/redaction sólo a partir de `docs/test-runs`.

## Límite de compatibilidad

El runner conserva `page` crudo para no romper specs existentes. Por ello, `observe_only` sólo es frontera verificable para acciones mutantes declaradas mediante `testkit.step`; un spec legacy que llame directamente `page.click()` o `locator.click()` puede eludir esa guardia.

Para consumidores safety-sensitive, los nuevos adapters no deben usar clicks/fill mutantes por fuera de `testkit.step`. Este límite debe considerarse parte del contrato hasta que exista una façade browser completamente cerrada y con evidencia de necesidad.

## Objetivo

Permitir que un host ejecute:

```text
browser session
-> HTTPS WebVisu
-> selectors/actions consumer-owned
-> screenshots/evidence
-> typed outcome
```

sin semántica CentroLogistico dentro de TestKit.

## Criterio de aceptación

### Implementado por source/contrato

- host aporta URL/selectors sin modificar TestKit;
- TLS self-signed es opt-in y scoped al target explícito;
- artifacts no incluyen headers/cookies/storage state y sanitizan valores secret-like;
- modo mutante requiere opt-in explícito para `testkit.step`;
- la primitive no expone APIs de force/download/online change ni escribe Modbus.

### Verificado en runtime

- fixture browser local determinista: PASS;
- adapter fake de host: PASS;
- runner Playwright real mediante Base/TestKit: PASS.

### Aún requiere ejecución/evidencia

- strict TLS FAIL ante self-signed;
- opt-in self-signed PASS contra fixture;
- timeout FAIL;
- inspección de `browser-run.json` real contra su schema y valores esperados;
- screenshot no sensible verificado como artifact real;
- cleanup browser verificado explícitamente en runtime;
- secret redaction inspeccionada en artifact real;
- piloto CentroLogistico WebVisu read-only con autorización separada.

No marcar este pendiente `PASS/CLOSED` hasta completar el gate runtime restante.

## Validación

```bash
node tests/framework/test_browser_blackbox_policy.mjs
```

Después:

```text
local fake HTTPS self-signed
-> strict TLS FAIL
-> opt-in self-signed PASS
-> timeout FAIL controlado
-> inspección browser-run.json/screenshot/redaction
-> CentroLogistico WebVisu read-only pilot con autorización separada
```

La prueba contra un PLC real se registra cuando exista autorización y evidencia. No se considera condición para validar el contrato reusable local.

## Riesgo

Medio por browser contra runtime industrial. Alto/crítico si un consumidor usa acciones HMI capaces de alcanzar salidas físicas sin aislamiento. TestKit no debe inferir que una acción visual es segura.

## Propietario técnico

TestKit es owner de browser lifecycle, clasificación de fallos y artifacts. El consumidor es owner de URL, selectors, credenciales, acciones y significado de estados.

## Fuera de alcance

- semántica CentroLogistico;
- mapa de pantallas de un consumidor;
- PLC program changes;
- Modbus maps;
- hardware HIL;
- control o autorización de salidas físicas.
