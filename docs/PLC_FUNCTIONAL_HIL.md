# PLC Functional HIL — explicit test-only write capability

## Purpose

This capability exists to drive **host-owned logical test bridges** during controlled HIL tests. It is deliberately separate from the read-only PLC APIs.

The existing `ModbusTcpReadOnlyClient` and `ReadOnlyApplicationMapProbe` remain FC03-only and expose no write API.

## Current implementation status

Implemented:

```text
ModbusTcpFunctionalHilClient
FC06 single holding register
logical stimulus id -> exact host-owned register
explicit low-level write enable
bounded allowlist
FunctionalHilGate / functional_hil_gate@1
FunctionalHilSession safe public route
runtime + application + bridge identity decision
sanitized bounded gate report
negative fake-server test proving 0 FC06 before a failed gate
no coils
no FC16
no wildcard/range writes
no address scanning
```

Verification state for the identity-gate cut:

```text
testKit/main implementation commit: 2494f929e29013224422513676b62bc7afde65a0
local framework execution: NOT_VERIFIED
GitHub commit statuses: NONE OBSERVED
hardware HIL: NOT_VERIFIED / OUT OF SCOPE
```

Still not implemented here:

```text
host adapter for BasePLC PLC Test Model
consumer application identity probes
consumer bridge identity probes
PLC-local bridge/lease implementation
hardware HIL execution
```

Continuity remains tracked in:

```text
docs/pendientes/20260821-1432-p1-plc-functional-hil-identity-integration.md
```

## Preferred public API

New integrations should use `FunctionalHilSession` instead of enabling the low-level client directly:

```php
require_once $testkitRoot . '/core/php/plc/bootstrap.php';

$gateEvidence = [
    'schema' => Testkit\Core\Plc\FunctionalHilGate::SCHEMA,
    'runtime' => ['status' => 'PASS', 'id' => 'runtime.expected', 'version' => '1'],
    'application' => ['status' => 'PASS', 'id' => 'app.expected', 'version' => '1'],
    'bridge' => ['status' => 'PASS', 'id' => 'bridge.expected', 'version' => '1'],
    'metadata' => ['consumer' => 'host-profile'],
];

$session = Testkit\Core\Plc\FunctionalHilSession::open(
    gateEvidence: $gateEvidence,
    host: $host,
    stimulusRegisters: [
        'input.raw_known' => 1200,
        'input.raw_level' => 1201,
    ],
    writeRequested: true,
    port: 502,
    unitId: 1,
    timeoutMs: 1500,
);

if (!$session->writesAllowed()) {
    throw new RuntimeException('Functional HIL gate did not allow writes.');
}

$session->writeStimulus('input.raw_known', 1);
```

The address map is host-owned. TestKit never discovers or guesses writable registers.

`FunctionalHilSession::gateReport()` returns only normalized identity evidence, sanitized bounded metadata, `write_requested` and `writes_allowed`. It intentionally excludes the physical register map.

## Gate contract

The versioned evidence envelope is:

```text
functional_hil_gate@1
```

Required identity components:

```text
runtime
application
bridge
```

Each component requires one explicit state:

```text
PASS
FAIL
UNKNOWN
UNAVAILABLE
```

The session enables writes only when:

```text
runtime.status == PASS
and application.status == PASS
and bridge.status == PASS
and stimulus allowlist is valid
and writeRequested == true
```

An incomplete envelope is rejected. `FAIL`, `UNKNOWN` and `UNAVAILABLE` never become PASS. Host-provided free text does not infer state.

Metadata is optional, scalar-only and bounded. Secret-like keys and register/address-like keys are rejected from gate metadata. Register mappings remain outside the report.

## Low-level compatibility API

The historical low-level client remains available for compatibility:

```php
$client = new Testkit\Core\Plc\ModbusTcpFunctionalHilClient(
    $host,
    ['input.raw_known' => 1200],
    writeEnabled: true,
);
```

This constructor is **not** an identity proof. New host integrations must prefer `FunctionalHilSession`; removing or breaking the historical constructor is outside this hardening cut.

## Fail-closed transport contract

- allowlist must be non-empty and contain at most 64 exact registers;
- duplicate addresses are rejected;
- callers write by logical stimulus id, not arbitrary address;
- only FC06 (single holding register) is implemented;
- no coil writes;
- no FC16/multiple-register writes;
- no wildcard/range writes;
- no address scanning;
- normal FC03 reads are delegated to `ModbusTcpReadOnlyClient`;
- Modbus exception, echo mismatch, timeout and connection errors fail explicitly;
- a blocked `FunctionalHilSession` fails with `write_disabled` before transport.

## Required host-owned evidence

TestKit validates the gate envelope, but it does not decide what identifies a concrete application. The host/consumer must produce the runtime/application/bridge evidence.

It must establish, with consumer-owned logic, that:

```text
runtime identity is expected
application identity is expected
logical test bridge identity is expected
allowlisted registers map only to that dedicated test bridge
```

TestKit must not encode Locker, Cargador, BasePLC or other consumer-specific application semantics.

A host must not point this capability at WAGO special registers or process-image output registers.

## Bridge lease boundary

The host bridge must implement a **PLC-local lease/timeout**. Loss of network, runner crash or missing heartbeat must release synthetic ownership locally without depending on TestKit, the server or Internet.

The identity gate does not implement or pretend to implement that PLC-local lease. `bridge.status == PASS` is host-owned evidence that the expected bridge is present; it is not remote cleanup authorization.

## Industrial safety boundary

Functional HIL writes are not authorization for physical actuation. A host using this API must keep its physical output path inhibited or absent while driving synthetic inputs. Interlocks required for people/equipment remain local and independent of TestKit.

The intended flow is:

```text
host identity probes
-> functional_hil_gate@1
-> runtime/application/bridge PASS
-> valid allowlist + explicit write opt-in
-> TestKit FC06 allowlisted stimulus
-> host test bridge with local lease
-> abstract logical input
-> host domain logic
-> shadow/effective state observation
```

Never:

```text
runtime DETECTED -> write enabled
TestKit -> physical %Q / actuator
```

## Relationship with BasePLC PLC Test Model

BasePLC exposes a transport-neutral `PlcExecutionBackend` and PLC Test Model executor. TestKit remains the owner of PLC transport primitives.

The host integration target is:

```text
BasePLC logical plan
-> Pruebas host adapter
-> host/consumer identity + signal maps
-> FunctionalHilSession + read-only APIs
-> host adapter observations
-> BasePLC snapshots/assertions/result
```

The PHP/Python bridge belongs to the host. TestKit must not import BasePLC internals and BasePLC must not copy TestKit Modbus clients.

## Framework self-tests

Targeted commands:

```bash
php -l core/php/plc/FunctionalHilGate.php
php -l core/php/plc/FunctionalHilSession.php
php tests/framework/test_plc_modbus_functional_hil.php
php tests/framework/test_plc_functional_hil_gate.php
```

Both framework tests are designed around local fake/deterministic resources and do not require PLC hardware. These commands are documented for reproducible validation; they are not claimed PASS by this document until execution evidence exists.
