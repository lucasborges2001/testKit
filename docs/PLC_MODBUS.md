# PLC Modbus TCP read-only infrastructure

## Scope

TestKit owns reusable PLC transport diagnostics and read-only application-map execution. Host projects own concrete register maps, meanings and business assertions.

Closed path:

```text
host project descriptor
-> TestKit
-> runtime-map detection
-> runtime gate
-> bounded FC03 read plan
-> neutral structural evidence
```

TestKit does **not** define project-specific PLC variables, `%MW` application contracts, PINs, boxes, workflows, output control or domain assertions.

`runtime profile != application map`.

A runtime profile identifies the Modbus/runtime contract exposed by the PLC. An application map is a host-owned descriptor that declares which FC03 windows belong to that application and on which runtime profiles that exact map is valid.

## Public PHP API

Load:

```php
require_once $testkitRoot . '/core/php/plc/bootstrap.php';
```

Public classes:

```text
Testkit\Core\Plc\ModbusTcpReadOnlyClient
Testkit\Core\Plc\ModbusTcpReadOnlyException
Testkit\Core\Plc\RuntimeProfileCatalog
Testkit\Core\Plc\RuntimeProfileDetector
Testkit\Core\Plc\ReadOnlyApplicationMapValidator
Testkit\Core\Plc\ReadOnlyApplicationMapProbe
```

`ModbusTcpReadOnlyClient` only constructs FC03 requests. It exposes no register/coil write method and no configurable Modbus function code.

If write-capable PLC testing is added later, it must use a separate capability/API with explicit opt-in. It must not be added to this read-only client or probe.

## Runtime-map profiles

Initial profiles:

```text
wago-pfc200-codesys2
wago-pfc200-eruntime
```

Profiles describe infrastructure/runtime, not applications.

### `wago-pfc200-codesys2`

Required signatures:

```text
0x2002..0x2004 => 0x1234, 0xAAAA, 0x5555
0x1040         => PLC state 1 or 2
```

WAGO documents the CODESYS V2 `%MW` flag area under `0x3000..0xFFFF`, but explicitly states that the actual addressable flag area depends on the current CODESYS memory arrangement. A host must validate its own range; TestKit does not turn the maximum range into an application guarantee.

### `wago-pfc200-eruntime`

Required signatures:

```text
0xFAA0..0xFAA2 => 0x1234, 0xAAAA, 0x5555
0xFA0D         => PLC state 1 or 2
```

Additional neutral evidence:

```text
0xFA17         Modbus process-image version
0xFA40..0xFA45 process-image size information
```

These registers identify runtime/process-image metadata. They do **not** establish where a particular host application's variables live.

Reference: WAGO I/O System 750 PFC200 manuals, sections `Modbus – CODESYS V2` and `Modbus – e!RUNTIME`; for e!RUNTIME, sections `12.2.4 Modbus Process Image Version` and `12.2.5 Modbus Process Image Registers`.

## Runtime detection semantics

`RuntimeProfileDetector` fails closed.

```text
DETECTED
UNKNOWN
AMBIGUOUS
PROFILE_MISMATCH
```

Rules:

- `auto`: exactly one profile must match.
- explicit profile: detected profile must equal the requested profile.
- no matches: `UNKNOWN`.
- multiple matches: `AMBIGUOUS`; no profile is selected.
- explicit contradiction: `PROFILE_MISMATCH`.
- no silent fallback.

## Read-only application map descriptor

Host projects pass a plain PHP array, typically stored in host-owned test support code:

```php
[
    'id' => 'example-app-map-v1',
    'supportedRuntimeProfiles' => [
        'wago-pfc200-codesys2',
    ],
    'windows' => [
        [
            'id' => 'main-block',
            'function' => 3,
            'startAddress' => 12416,
            'quantity' => 27,
        ],
    ],
    'interRequestDelayMs' => 0,
]
```

TestKit treats `id` values as opaque identifiers. It does not infer semantics from them.

Validation is fail-closed:

```text
plan id: non-empty safe identifier
supportedRuntimeProfiles: non-empty, unique, all present in RuntimeProfileCatalog
windows: non-empty, unique ids
function: integer 3 only
startAddress: 0..65535
quantity: 1..125
startAddress + quantity - 1: <=65535
```

No wildcard ranges, auto-address discovery, negative offsets or scan-all mode exist.

## Safety budgets

A one-shot application-map probe is bounded to:

```text
max windows:               16
max registers/request:     125   (Modbus FC03 protocol limit)
max total registers/plan:  1024  (2 KiB register payload)
inter-request delay:       0..1000 ms
```

The 16-window/1024-register policy keeps a diagnostic run deterministic and well below a full address-space scan while leaving substantial headroom over current small application maps. It is a TestKit safety policy, not a PLC address-space claim.

## Runtime gate and execution order

Schema validation happens before hardware I/O so malformed descriptors fail without touching a PLC. Once a plan is valid, application reads follow this gate:

```text
runtime detection
-> DETECTED required
-> detected profile must be in supportedRuntimeProfiles
-> only then execute application windows
```

Status mapping:

```text
DETECTED + supported   -> execute plan
DETECTED + unsupported -> BLOCKED
UNKNOWN                -> FAIL
AMBIGUOUS              -> FAIL
PROFILE_MISMATCH       -> FAIL
```

A blocked/failed runtime gate executes zero application windows.

## Application-map probe API

```php
$client = new ModbusTcpReadOnlyClient($host, $port, $unitId, $timeoutMs);
$detector = new RuntimeProfileDetector();
$probe = ReadOnlyApplicationMapProbe::fromClient($client, $detector);

$result = $probe->run($applicationMap, $requestedRuntimeProfile);
```

Return shape:

```text
result.evidence
result.valuesByWindow
```

`evidence` is safe structural evidence and contains no application register dump. It includes:

```text
status
mode=readonly
transport=modbus-tcp
readonlyInvariant=true
runtime
plan id / supported profiles / budgets
windows:
  id
  function
  startAddress
  endAddress
  quantity
  registerCount
  valid
  failureStage
  durationMs
durationMs
failureStage
failureReason
```

`valuesByWindow` exists only in-memory for host-owned assertions. The host decides whether any application value is safe to persist in its own evidence.

## Neutral artifacts and reporting

The existing runtime-profile CLI persists:

```text
.testkit/plc/modbus-readonly-profile/latest.json
```

The application-map executor itself is a PHP API in this phase. No second file-based read-plan CLI is added in 02A because the real consumer already runs host PHP tests through TestKit's `infra-php` suite. Adding JSON file loading/path policy now would create a second public configuration surface without a second concrete consumer. A generic CLI can be added later if another project needs it.

Host projects may persist the probe's `evidence` using TestKit's existing artifact/report infrastructure. They must not persist the full `valuesByWindow` result by default.

## WAGO e!RUNTIME process-image boundary

Documented generic facts:

- `0xFA17` reports the Modbus process-image version.
- `0xFA40..0xFA45` report process-image size information.
- `0xFAA0..0xFAA2` are fixed constants.
- `0xFA0D` reports PLC state.

Observed values can confirm a runtime/profile and process-image dimensions, but **size metadata alone does not map host variables to Modbus addresses**. TestKit therefore does not derive an application map from these registers and does not scan `0..65535` looking for one.

If a host cannot produce documented application windows for e!RUNTIME, application-map discovery remains `BLOCKED` rather than guessed.

## Framework self-test

```bash
php tests/framework/test_plc_modbus_readonly_profiles.php
```

Coverage includes:

- FC03 encoding/decoding and Modbus exception propagation;
- CODESYS2 and e!RUNTIME detection;
- `UNKNOWN`, `AMBIGUOUS`, `PROFILE_MISMATCH`;
- one and multiple application windows;
- `quantity=125`;
- rejection of FC06, FC16 and unknown functions;
- invalid/overflowing addresses and quantities;
- unknown runtime profiles and duplicate ids;
- window and total-register budgets;
- `BLOCKED` detected-but-unsupported runtime;
- absence of public write-like methods;
- local fake TCP/MBAP execution of an e!RUNTIME read plan.

A local fake server PASS is infrastructure evidence only. It is not hardware PASS.

## Ownership boundary

### TestKit owns

- Modbus TCP transport and MBAP framing;
- FC03 read-only primitive;
- timeout/range validation;
- runtime detection;
- application-map schema validation;
- runtime gate;
- bounded read-plan execution;
- neutral evidence shape and structural validation.

### Host project owns

- concrete application maps;
- which runtime profiles support each map;
- register meanings;
- domain fixtures and assertions;
- decisions about persisting application values.

## Future direction — not implemented here

Potential later capabilities include HMI, serial/scanner and multi-device orchestration, but they are not part of this contract.

For HMI specifically, reading variables does not demonstrate that a downloaded touchscreen screen works visually/interactively. Any future HMI capability must have a verifiable observation surface and an interaction-injection surface supported by the actual hardware/software. TestKit does not assume Vijeo Designer or HMIS5T exposes such an API.
