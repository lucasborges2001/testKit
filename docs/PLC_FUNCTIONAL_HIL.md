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
explicit write enable
bounded allowlist
no coils
no FC16
no wildcard/range writes
no address scanning
```

Not implemented in the current baseline:

```text
versioned application/bridge identity gate envelope
public session/factory that refuses write enable unless runtime+application+bridge identity all PASS
host adapter for BasePLC PLC Test Model
consumer application identity probes
hardware HIL execution
```

The implementation gap above is tracked in:

```text
docs/pendientes/20260821-1432-p1-plc-functional-hil-identity-integration.md
```

## Public API

```php
require_once $testkitRoot . '/core/php/plc/bootstrap.php';

$client = new Testkit\Core\Plc\ModbusTcpFunctionalHilClient(
    $host,
    [
        'input.raw_known' => 1200,
        'input.raw_level' => 1201,
    ],
    writeEnabled: true,
    port: 502,
    unitId: 1,
    timeoutMs: 1500,
);

$client->writeStimulus('input.raw_known', 1);
```

The address map is host-owned. TestKit never discovers or guesses writable registers.

The example above demonstrates the current low-level public client. `writeEnabled: true` is **not** evidence that runtime/application/bridge identity was verified; callers remain responsible for the required host gate until the pending hardening contract is implemented.

## Fail-closed contract

- write capability must be explicitly enabled by the host;
- allowlist must be non-empty and contains at most 64 exact registers;
- duplicate addresses are rejected;
- callers write by logical stimulus id, not arbitrary address;
- only FC06 (single holding register) is implemented;
- no coil writes;
- no FC16/multiple-register writes;
- no wildcard/range writes;
- no address scanning;
- normal FC03 reads are delegated to `ModbusTcpReadOnlyClient`;
- Modbus exception, echo mismatch, timeout and connection errors fail the test.

## Required host gate

A host must perform its runtime/application identity gate **before** enabling writes. Runtime profile detection alone is insufficient evidence of application identity.

The host must prove, using host/consumer-owned logic, that:

```text
runtime identity is expected
application identity is expected
logical test bridge identity is expected
allowlisted registers map only to that dedicated test bridge
```

TestKit must not encode Locker, Cargador, BasePLC or other consumer-specific application semantics.

A host must not point this capability at WAGO special registers or process-image output registers.

The host bridge must implement a **PLC-local lease/timeout**. Loss of network, runner crash or missing heartbeat must release synthetic ownership locally without depending on TestKit, the server or Internet. The released state must not be interpreted as a safe physical state; it should return the synthetic observation to the host-defined unknown/unowned condition.

## Industrial safety boundary

Functional HIL writes are not authorization for physical actuation. A host using this API must keep its physical output path inhibited or absent while driving synthetic inputs. Interlocks required for people/equipment remain local and independent of TestKit.

The intended flow is:

```text
host identity probes
-> runtime/application/bridge gate PASS
-> TestKit FC06 allowlisted stimulus
-> host test bridge with local lease
-> abstract logical input
-> host domain logic
-> shadow/effective state observation
```

Not:

```text
runtime DETECTED
-> writeEnabled=true
```

and never:

```text
TestKit -> physical %Q / actuator
```

## Relationship with BasePLC PLC Test Model

BasePLC currently exposes a transport-neutral `PlcExecutionBackend` and generic PLC Test Model executor. TestKit remains the owner of PLC transport primitives.

A future host integration should compose them as:

```text
BasePLC logical plan
-> host adapter
-> host/consumer identity + signal maps
-> TestKit public Functional HIL/read-only APIs
-> host adapter observations
-> BasePLC snapshots/assertions/result
```

The PHP/Python bridge belongs to the host. TestKit must not import BasePLC internals and BasePLC must not copy TestKit Modbus clients.

## Framework self-test

```bash
php tests/framework/test_plc_modbus_functional_hil.php
```

The framework test uses only a local fake Modbus server. It does not contact PLC hardware.

The pending identity-gate hardening must also be testable without PLC hardware and must prove that a non-PASS gate produces zero FC06 writes.