# PLC Modbus TCP infrastructure

## Scope

TestKit owns reusable PLC transport primitives and consumer-neutral validation helpers. Concrete application maps, signal meanings and domain assertions remain consumer-owned.

Two capabilities are intentionally separated:

```text
READ-ONLY
  ModbusTcpReadOnlyClient
  RuntimeProfileDetector
  ReadOnlyApplicationMapProbe

FUNCTIONAL HIL
  FunctionalHilGate
  FunctionalHilSession
  FunctionalHilLifecycle
  exact allowlisted FC06
```

The read-only surface remains FC03-only and exposes no write API.

## Read-only runtime profiles

Built-in infrastructure profiles currently include:

```text
wago-pfc200-codesys2
wago-pfc200-eruntime
```

These profiles identify runtime-level Modbus evidence. They are not application maps and contain no Locker-specific addresses.

A host application map remains explicit:

```php
[
    'id' => 'example-app-map-v1',
    'supportedRuntimeProfiles' => ['wago-pfc200-codesys2'],
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

TestKit never derives that map from runtime metadata and never scans the Modbus address space looking for one.

## Runtime detection

`RuntimeProfileDetector` fails closed with:

```text
DETECTED
UNKNOWN
AMBIGUOUS
PROFILE_MISMATCH
```

An application read plan executes only after a unique compatible runtime profile is detected. Unsupported or contradictory runtime evidence executes zero application windows.

## FC03 read-plan budgets

The existing application-map validator remains bounded to:

```text
max windows:               16
max registers/request:     125
max total registers/plan:  1024
inter-request delay:       0..1000 ms
```

No wildcard ranges, auto-address discovery or scan-all mode are exposed.

## Functional HIL transport

`ModbusTcpFunctionalHilClient` adds only Modbus FC06 single-holding-register writes behind an exact logical allowlist.

Transport contract:

```text
logical stimulus id -> one exact UINT16 register
max allowlist entries: 64
unique writable addresses
explicit write enable
FC06 only
no coils
no FC16
no public raw-address write method
```

New consumers should not directly treat the low-level client as authorization. The preferred route is:

```text
FunctionalHilGate
-> FunctionalHilSession
-> optional FunctionalHilLifecycle
-> writeStimulus(logicalId, value)
```

Identity `FAIL`, `UNKNOWN` and `UNAVAILABLE` disable writes before transport.

## Snapshot and scan helpers are transport-neutral

`CoherentSnapshotReader` and `ScanDrivenWait` accept callbacks instead of Modbus addresses. This keeps register layouts and signal IDs in the consuming project.

A consumer may back those callbacks with FC03 reads, a simulator or another validated observation transport. TestKit does not couple BasePLC contracts or application semantics to Modbus.

## Multi-runtime boundary

The profile catalog is extensible for future runtime profiles, but additions must remain explicit and evidence-based:

```text
profile id explicit
exact probe registers
explicit decode/match rules
no writes
no auto-discovery
```

Adding a future runtime profile does not authorize an application map for that runtime. The consumer must separately declare supported application windows.

## Evidence and artifacts

Read-only application probes keep raw application values in memory under `valuesByWindow` and omit them from neutral structural evidence by default.

Functional HIL lifecycle/snapshot/scan/stress artifacts use `PlcArtifact`, which separates execution state from pass/fail state and redacts secret-like metadata.

A local fake-server PASS establishes TestKit transport/framework behavior only. It does not establish PLC hardware identity, concrete host mappings, compiled CoDeSys applications or physical I/O safety.

## Ownership

### TestKit owns

- Modbus TCP MBAP framing;
- FC03 read primitive;
- exact allowlisted FC06 single-register primitive;
- runtime profile detection;
- bounded application read-plan execution;
- identity-gated Functional HIL session;
- lifecycle cleanup orchestration;
- coherent snapshot primitive;
- scan-driven waiting;
- stress/soak orchestration;
- structural safe artifacts.

### Consumers own

- concrete register/signal maps;
- application and bridge identity evidence;
- logical arm/heartbeat/release signal IDs and values;
- lease implementation;
- payload decoding and commit/version rules;
- post-cleanup safety verifiers;
- domain fixtures/assertions;
- decisions about persisting application values.

### BasePLC owns

- IEC-ST/source analysis;
- PLC test-plan/result contracts;
- transport-neutral execution model;
- assertions, budgets and logical snapshots.

No Modbus implementation should be copied into BasePLC or consumer repositories.

## Framework self-tests

```bash
php tests/framework/test_plc_modbus_functional_hil.php
php tests/framework/test_plc_functional_hil_gate.php
php tests/framework/test_plc_modbus_readonly_profiles.php
php tests/framework/test_plc_hil_validation_primitives.php
```

All tests use local deterministic resources. No PLC real or hardware HIL is required.
