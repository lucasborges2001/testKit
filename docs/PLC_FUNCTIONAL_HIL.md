# PLC Functional HIL — explicit test-only write capability

## Purpose

This capability exists to drive **host-owned logical test bridges** during controlled HIL tests. It is deliberately separate from the read-only PLC APIs.

The existing `ModbusTcpReadOnlyClient` and `ReadOnlyApplicationMapProbe` remain FC03-only and expose no write API.

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

A host must perform its runtime/application identity gate **before** enabling writes. The host must also prove that every allowlisted register is mapped only to a dedicated test bridge and cannot directly address physical outputs.

A host must not point this capability at WAGO special registers or process-image output registers.

The host bridge must implement a **PLC-local lease/timeout**. Loss of network, runner crash or missing heartbeat must release synthetic ownership locally without depending on TestKit, the server or Internet. The released state must not be interpreted as a safe physical state; it should return the synthetic observation to the host-defined unknown/unowned condition.

## Industrial safety boundary

Functional HIL writes are not authorization for physical actuation. A host using this API must keep its physical output path inhibited or absent while driving synthetic inputs. Interlocks required for people/equipment remain local and independent of TestKit.

The intended flow is:

```text
TestKit FC06 allowlisted stimulus
-> host test bridge with local lease
-> abstract logical input
-> host domain logic
-> shadow/effective state observation
```

Not:

```text
TestKit -> physical %Q / actuator
```

## Framework self-test

```bash
php tests/framework/test_plc_modbus_functional_hil.php
```

The framework test uses only a local fake Modbus server. It does not contact PLC hardware.
