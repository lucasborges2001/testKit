# PLC Functional HIL — safe reusable validation infrastructure

## Purpose

TestKit provides consumer-neutral primitives for controlled PLC Functional HIL. The writable surface is deliberately narrow and remains separate from the read-only PLC APIs.

The safe route is:

```text
consumer-owned identity evidence
-> FunctionalHilGate
-> FunctionalHilSession
-> FunctionalHilLifecycle
-> exact logical FC06 stimuli
-> scan/snapshot primitives
-> consumer assertions
-> bounded release/cleanup verification
```

TestKit does not own application addresses, Locker semantics, bridge identities, lease values, IEC-ST analysis or physical-output authorization.

## Implementation status

Implemented and locally framework-tested in this expansion:

```text
FunctionalHilGate / functional_hil_gate@1
FunctionalHilSession compatibility API
FunctionalHilLifecycle / testkit.plc-functional-hil-session.v1
ModbusTcpFunctionalHilClient
FC06 single holding register only
exact logical stimulus id -> exact consumer-owned register allowlist
explicit write opt-in
runtime + application + bridge identity gate
CoherentSnapshotReader / testkit.plc-snapshot-read.v1
ScanDrivenWait / testkit.plc-scan-wait.v1
StressSoakRunner / testkit.plc-stress-result.v1
PlcArtifact safe execution/status envelope
bounded cleanup and post-cleanup verification
secret redaction in PLC artifacts
no coils
no FC16
no wildcard/range writes
no address scanning
```

Verification in this repository uses local deterministic resources only. PLC hardware, runtime HIL and physical I/O remain `NOT_EXECUTED`.

## Identity-gated session

New integrations continue to open the transport through `FunctionalHilSession`:

```php
$session = Testkit\Core\Plc\FunctionalHilSession::open(
    gateEvidence: $consumerOwnedEvidence,
    host: $host,
    stimulusRegisters: $consumerOwnedExactAllowlist,
    writeRequested: true,
    port: 502,
    unitId: 1,
    timeoutMs: 1500,
);
```

Writes are enabled only when all of the following are true:

```text
runtime.status == PASS
application.status == PASS
bridge.status == PASS
writeRequested == true
stimulus id is present in the exact allowlist
```

`FAIL`, `UNKNOWN` and `UNAVAILABLE` never become PASS. A blocked session fails before writable transport.

The historical `ModbusTcpFunctionalHilClient` constructor remains available for compatibility. It is not an identity proof and should not be the preferred integration route.

## Lifecycle orchestration

`FunctionalHilLifecycle` is intentionally a layer above `FunctionalHilSession`; the session remains small and compatible.

The consumer supplies opaque logical IDs and values for:

```text
arm
heartbeat / scenario stimuli
release
cleanup writes
post-cleanup verifiers
```

TestKit validates that lifecycle write IDs are already present in the session allowlist. It does not infer addresses or application semantics.

Execution semantics:

```text
optional pre-arm gate
-> arm
-> consumer scenario
-> release
-> finally:
     best-effort release if arm may have reached the PLC
     bounded cleanup writes
     bounded consumer-owned post-cleanup verifiers
```

Important failure rule: the lifecycle marks arm as attempted before the FC06 response is received. If the request may have reached the PLC but the response is lost, cleanup still executes.

Cleanup is idempotent for one lifecycle instance. A second cleanup call reuses the original cleanup report and emits no new writes.

A cleanup error never authorizes continuation. Any failed release, cleanup write or post-cleanup verifier keeps the final lifecycle result failed.

## Coherent snapshots

`CoherentSnapshotReader` formalizes a consumer-neutral seqlock-style read:

```text
head_before
payload
head_after/tail
```

The snapshot is accepted only when:

```text
head_before === tail_after
and optional commit predicate passes
and optional version/parity predicate passes
```

The consumer provides callbacks for reads, decode and predicates. TestKit does not know the payload layout.

The retry budget is finite. Result outcomes distinguish:

```text
PASS
FAIL
TIMEOUT
INCONSISTENT
TRANSPORT_ERROR
```

Decoded payload is returned in-memory separately from the structural artifact so TestKit does not persist consumer application values by default.

## Scan-driven waiting

`ScanDrivenWait` provides:

```text
waitUntilScanDelta(...)
waitUntilPredicateByScan(...)
```

The primitive treats actual counter advancement as authority. It does not assume a 20 ms cycle or any other fixed PLC period.

The consumer supplies the scan read callback, expected delta/predicate, budgets and, if required, an explicit counter modulus for wrap handling. A stalled counter terminates with a bounded timeout instead of looping indefinitely.

An optional caller-controlled polling hook can pace reads; TestKit does not make host sleeps evidence of PLC progress.

## Stress / soak

`StressSoakRunner` repeatedly invokes a caller-provided scenario. It never connects to a PLC or enables HIL by itself.

Supported policies:

```text
stop-on-first-fail
keep-going
bounded failure collection
```

Base metrics:

```text
iterations_requested
iterations_completed
failures
transport_errors
cleanup_failures
snapshot_inconsistencies
scan_start
scan_end
scan_delta
elapsed_host_time_ms
```

Optional consumer-supplied metrics are accepted only when explicitly present:

```text
watchdogCount
overrunCount
applicationErrorCount
```

No metric is invented if the consumer cannot observe it.

## Structured PLC artifacts

The PLC primitives emit versioned structural artifacts such as:

```text
testkit.plc-functional-hil-session.v1
testkit.plc-snapshot-read.v1
testkit.plc-scan-wait.v1
testkit.plc-stress-result.v1
```

Top-level artifact state separates execution from result:

```text
execution: EXECUTED | NOT_EXECUTED
status:    PASS | FAIL | UNKNOWN | UNAVAILABLE
```

Detailed outcomes remain explicit under artifact data and are not promoted to PASS.

`PlcArtifact` recursively redacts secret-like fields and common inline authorization/Bearer material. Passwords, secrets, tokens, PIN-like fields, auth headers and cookies must not be persisted as PLC artifact metadata.

## Safety boundary

Functional HIL is not physical-output authorization.

TestKit does not provide:

```text
coil writes
FC05 / FC15 / FC16
raw arbitrary FC06
address scanning
Run / Stop / Download / Online Change / Force
physical %Q authorization
consumer bridge implementation
consumer lease policy
application maps
```

The intended downstream architecture remains:

```text
BasePLC logical plan/assertions
-> consumer adapter
-> TestKit safe HIL primitives
-> consumer PLC lab bridge
-> coherent snapshot
-> BasePLC result/assertions
```

BasePLC remains transport-neutral. TestKit must not import or reproduce its IEC-ST analysis.

## Framework validation

Reproducible local gates:

```bash
php -l core/php/plc/FunctionalHilGate.php
php -l core/php/plc/FunctionalHilSession.php
php -l core/php/plc/PlcArtifact.php
php -l core/php/plc/CoherentSnapshotReader.php
php -l core/php/plc/ScanDrivenWait.php
php -l core/php/plc/FunctionalHilLifecycle.php
php -l core/php/plc/StressSoakRunner.php

php tests/framework/test_plc_modbus_functional_hil.php
php tests/framework/test_plc_functional_hil_gate.php
php tests/framework/test_plc_modbus_readonly_profiles.php
php tests/framework/test_plc_hil_validation_primitives.php
```

The new framework test covers deterministic failure paths including identity UNKNOWN with zero FC06, unallowlisted stimulus with zero FC06, pre-arm failure with zero FC06, exceptions after arm, heartbeat/release/cleanup failures, transport failure after arm, idempotent cleanup, torn snapshots, stalled scans and artifact secret redaction.

These framework gates do not constitute hardware HIL PASS.
