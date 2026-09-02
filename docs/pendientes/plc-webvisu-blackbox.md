# PLC WebVisu black-box reusable

Fecha: 2026-09-02
Estado: `OPEN`
Prioridad: P1

## Evidencia

TestKit ya posee browser runner/Playwright, reporting/artifacts, probes PLC read-only y Functional HIL separados. Un consumidor real, CentroLogistico, dispone de un WAGO PFC200 con WebVisu accesible y necesita automatizar pruebas black-box sin introducir código de test dentro del PLC.

La primitive reusable específica para sesiones WebVisu, lifecycle, screenshots y fallos de reachability/TLS todavía no está definida como contrato público TestKit.

## Objetivo

Agregar una capacidad reusable para que un host pueda ejecutar:

```text
browser session
-> HTTPS WebVisu
-> selectors/actions consumer-owned
-> screenshots/evidence
-> typed outcome
```

La primitive debe ser neutral respecto de nombres de pantallas, credenciales, variables PLC y dominio del consumidor.

## Dependencias

- Browser runner actual de TestKit.
- Contrato de artifacts/reporting vigente.
- Consumer pilot CentroLogistico.
- Política explícita para certificados self-signed de redes de commissioning.
- URLs y credenciales suministradas por el host mediante configuración local, nunca hardcodeadas.

## Trabajo pendiente

1. Definir `WebVisuSession` o primitive equivalente sobre el browser runner existente, sin crear un segundo stack de Playwright.
2. Lifecycle: open, ready, step, screenshot, close y cleanup determinista.
3. Probe de reachability HTTPS y clasificación de timeout/TLS/HTTP/browser errors.
4. Permitir configuración host de `ignoreHTTPSErrors` únicamente de forma explícita para commissioning; default seguro.
5. Artifacts sanitizados: screenshots, timings y outcome sin credenciales ni valores secret-like.
6. Fake/local deterministic fixture para self-tests, sin PLC real.
7. Integración con host-agent/suite runner manteniendo exit codes y reporting canónicos.
8. Separar perfiles:
   - `observe`: carga/navegación/lectura;
   - `mutating`: acciones declaradas por consumidor y deshabilitadas por defecto.

## Criterio de aceptación

- self-test local determinista cubre open/ready/navigation/screenshot/timeout/TLS failure/cleanup;
- host puede aportar URL/selectors sin modificar TestKit;
- browser failures producen `FAIL/UNKNOWN`, nunca PASS silencioso;
- artifacts no contienen password/token;
- no hay dependencia con CentroLogistico ni direcciones PLC;
- el modo mutante requiere opt-in explícito;
- la primitive no expone APIs de force/download/online change ni escribe Modbus.

## Validación

```text
contract/static
-> local fake web fixture
-> browser self-tests
-> host adapter fake
-> CentroLogistico WebVisu read-only pilot
```

La prueba contra un PLC real se registra en `docs/verificaciones/` cuando exista evidencia. No se considera condición para validar el self-test reusable.

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
