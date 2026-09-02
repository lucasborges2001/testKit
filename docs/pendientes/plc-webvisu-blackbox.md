# PLC WebVisu black-box reusable

Fecha: 2026-09-02
Estado: `IMPLEMENTED / RUNTIME_PARTIAL_PASS`
Prioridad: P1

## Evidencia verificada

TestKit ya dispone de browser runner/Playwright, reporting/artifacts y política black-box reusable. La auditoría inicial del corte `188236fe802fdfb7265400cdb0beafbed441c223` detectó `ignoreHTTPSErrors: true` incondicional; el corte posterior incorporó TLS estricto por defecto, self-signed opt-in scoped al target, `observe_only` por defecto, `testkit.step()` y `browser-run.json` sanitizado.

### Runtime R5

```text
Pruebas tested_sha=37a58ad53bbdd488b37cb5abc8ca4fa1ca214b84
Base=51f7b4c71973fbd1386c6c655ffcb0b56a626324
Base/TestKit=dd182a4be52b761241237a4ee5396bda3f4f3d91
report=Pruebas/docs/test-runs/1788379971.md
Pipeline=PASS
Evidence=PASS
Product=PASS
browser fake=PASS
browser artifact contract=PASS
```

R5 verificó en browser real:

- `browser-run.json` schema/estado esperado;
- `tls_policy=strict`;
- `action_mode=observe_only`;
- screenshot no sensible persistido y no vacío;
- redacción de query secret-like;
- ausencia del literal ficticio `fake-runtime-secret` en JSON;
- prevención de artifact stale en el consumidor fake.

### Portabilidad de screenshots — implementada y validada

La auditoría posterior detectó que `steps[].screenshot` y `failure_screenshot` contenían rutas absolutas del contenedor (`/workspace/project/...`).

Se implementó en TestKit:

```text
candidate=e5d0366ffc665df0de9ddf6e53f5403a535693bb
```

Cambios:

- referencias de screenshot relativas a `artifactsDir`;
- guardia fail-closed si la ruta intenta salir de `artifactsDir`;
- `failure_screenshot` usa el mismo contrato portable;
- self-test actualizado.

El candidato se integró mediante:

```text
Base=5963f4a5fb828b214c69d7fa536ac16b9017a4de
Base/TestKit=e5d0366ffc665df0de9ddf6e53f5403a535693bb
```

Runtime R6:

```text
Pruebas tested_sha=826e30c89ab838cd654cb54b935ab8bf9223a4c8
report=Pruebas/docs/test-runs/1788380289.md
Pipeline=PASS
Evidence=PASS
Product=PASS
browser fake=PASS
portable artifact contract=PASS
```

El contrato host exigió explícitamente que `steps[].screenshot` no fuera absoluto, no contuviera `..` y resolviera a un archivo real dentro del directorio de artifacts.

Contrato R7:

```text
Pruebas tested_sha=e9de1c545617e87b5d4630566e8df31072aa0d5d
report=Pruebas/docs/test-runs/1788380360.md
Pipeline=PASS
Evidence=PASS
Product=PASS
commands=7/7 PASS
TestKit browser policy self-test=PASS
```

Por tanto:

```text
ARTIFACT_SCREENSHOT_PATH_PORTABILITY=PASS
```

## Límite de compatibilidad

El runner conserva `page` crudo para no romper specs existentes. `observe_only` es frontera verificable para acciones mutantes declaradas mediante `testkit.step`; un spec legacy que invoque directamente `page.click()` o `locator.click()` puede eludir esa guardia.

Los consumidores safety-sensitive deben declarar mutaciones mediante `testkit.step` hasta que exista evidencia suficiente para justificar una façade browser cerrada.

## Gates verificados

```text
fixture browser local determinista       PASS
adapter fake de host                     PASS
runner Playwright real                   PASS
Base -> TestKit -> browser               PASS
browser-run.json                         PASS
screenshot persistido                    PASS
secret-like redaction                    PASS
screenshot refs portables                PASS
self-test browser policy                 PASS
```

## Pendiente real

Todavía falta evidencia runtime para:

1. strict TLS FAIL ante self-signed;
2. opt-in self-signed PASS contra fixture local;
3. timeout FAIL controlado;
4. cleanup browser verificado explícitamente;
5. piloto CentroLogistico WebVisu read-only, sólo con autorización separada.

No cerrar este pendiente hasta completar los gates locales restantes. La prueba contra PLC real no es condición para validar el contrato reusable local, pero sí para declarar el piloto consumidor.

## Validación siguiente

```text
local fake HTTPS self-signed
-> strict TLS FAIL esperado
-> opt-in self-signed PASS esperado
-> timeout FAIL controlado
-> cleanup explícito
```

Después, y sólo con autorización separada:

```text
CentroLogistico WebVisu read-only pilot
```

## Riesgo

Medio por browser contra runtime industrial. Alto/crítico si un consumidor usa acciones HMI que puedan alcanzar salidas físicas sin aislamiento. TestKit no debe inferir que una acción visual es segura.

## Ownership

TestKit es owner de browser lifecycle, TLS policy, clasificación de fallos y artifacts. El consumidor es owner de URL, selectors, credenciales, acciones y semántica de estados.

## Fuera de alcance

- semántica CentroLogistico;
- PLC program changes;
- Modbus maps;
- hardware HIL;
- control o autorización de salidas físicas.
