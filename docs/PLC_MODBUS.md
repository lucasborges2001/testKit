# PLC Modbus TCP read-only infrastructure

## Scope

TestKit owns reusable PLC transport diagnostics. Host projects own application register maps and business semantics.

The closed capability in this phase is deliberately read-only:

```text
host project
-> TestKit
-> Modbus TCP
-> FC03 Read Holding Registers
-> runtime-map detection
-> persisted neutral evidence
```

TestKit does **not** define project-specific PLC variables, `%MW` application contracts, PINs, boxes, workflows, output control or domain assertions.

## Public PHP API

Host projects may load:

```php
require_once $testkitRoot . '/core/php/plc/bootstrap.php';
```

Classes:

```text
Testkit\Core\Plc\ModbusTcpReadOnlyClient
Testkit\Core\Plc\ModbusTcpReadOnlyException
Testkit\Core\Plc\RuntimeProfileCatalog
Testkit\Core\Plc\RuntimeProfileDetector
```

`ModbusTcpReadOnlyClient` only constructs FC03 requests. It exposes no register/coil write method and no configurable Modbus function code.

If write-capable PLC testing is added later, it must use a separate capability/API with explicit opt-in. It must not be added to the read-only client.

## Runtime-map profiles

Initial profiles:

```text
wago-pfc200-codesys2
wago-pfc200-eruntime
```

Profiles describe the runtime/map exposed through Modbus. They are not application profiles.

### `wago-pfc200-codesys2`

Required read-only signatures:

```text
0x2002..0x2004 => 0x1234, 0xAAAA, 0x5555
0x1040         => PLC state 1 or 2
```

The WAGO PFC200 CODESYS V2 mapping also documents the `%MW` flag area under `0x3000..0xFFFF`, but the actual addressable extent depends on the current CODESYS memory arrangement. Host projects must therefore validate their own application range rather than assuming the maximum documented range is allocated.

### `wago-pfc200-eruntime`

Required read-only signatures:

```text
0xFAA0..0xFAA2 => 0x1234, 0xAAAA, 0x5555
0xFA0D         => PLC state 1 or 2
```

Additional evidence:

```text
0xFA17         Modbus process-image version
0xFA40..0xFA45 process-image sizes
```

These are runtime/system probes only. They do not identify an application register map.

## Detection semantics

`RuntimeProfileDetector` always fails closed.

Possible states:

```text
DETECTED
UNKNOWN
AMBIGUOUS
PROFILE_MISMATCH
```

Rules:

- `auto`: exactly one matching profile is required.
- explicit profile: the detected profile must equal the requested profile.
- no matches: `UNKNOWN`.
- multiple matches: `AMBIGUOUS`; no profile is selected.
- explicit profile contradicted by one detected profile: `PROFILE_MISMATCH`.
- there is no silent fallback from an explicit profile to another runtime.

## Generic CLI

Run through the TestKit wrapper so the host project's selected test env is available:

```bash
export TESTKIT_PROJECT_ROOT=/absolute/path/to/project

./bin/testkit run --rm \
  -e TESTKIT_PLC_ENABLED \
  -e TESTKIT_PLC_HOST \
  -e TESTKIT_PLC_PORT \
  -e TESTKIT_PLC_UNIT_ID \
  -e TESTKIT_PLC_TIMEOUT_MS \
  -e TESTKIT_PLC_PROFILE \
  testkit php scripts/plc_modbus_profile.php --json
```

Configuration:

```env
TESTKIT_PLC_ENABLED=1
TESTKIT_PLC_HOST=192.168.x.x
TESTKIT_PLC_PORT=502
TESTKIT_PLC_UNIT_ID=1
TESTKIT_PLC_TIMEOUT_MS=1500
TESTKIT_PLC_PROFILE=auto
```

Supported explicit profile examples:

```env
TESTKIT_PLC_PROFILE=wago-pfc200-codesys2
TESTKIT_PLC_PROFILE=wago-pfc200-eruntime
```

Real PLC addresses belong in the host project's ignored `test/.env.test` or `.env.test`, not in TestKit.

## Artifact

Default host-project artifact:

```text
.testkit/plc/modbus-readonly-profile/latest.json
```

Stable top-level intent:

```text
schema=testkit.plc-modbus-readonly-profile.v1
mode=readonly
transport=modbus-tcp
status=PASS|FAIL|BLOCKED
result.readonlyInvariant=true
result.requestedProfile
result.detectionStatus
result.detectedProfile
```

The artifact records only neutral profile probes defined by TestKit. It does not perform arbitrary host-project register dumps.

## Exit behavior

```text
0  profile detected and accepted
1  FAIL: unknown, ambiguous, mismatch, configuration/resolution or transport/profile failure
2  BLOCKED: TESTKIT_PLC_ENABLED is not exactly 1
```

`BLOCKED` is not PASS.

## Host-project responsibility

A consuming repository may map its own contract after detection, for example:

```text
profile A -> application register map A
profile B -> application register map B
```

That mapping, its expected values, and any domain workflow remain in the consuming repository.

The consumer must not modify TestKit's runtime detector to encode project-specific variables.

## Framework self-test

```bash
php tests/framework/test_plc_modbus_readonly_profiles.php
```

The test covers:

- FC03 MBAP/PDU encoding and decoding;
- Modbus exception propagation;
- absence of write-like public methods/markers;
- CODESYS2 profile detection;
- e!RUNTIME profile detection;
- `UNKNOWN`;
- `AMBIGUOUS`;
- `PROFILE_MISMATCH`.
