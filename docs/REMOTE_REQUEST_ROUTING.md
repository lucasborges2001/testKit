# Remote request routing

`testkit-remote-request-route` provides reusable executor eligibility semantics for host-owned remote-test controllers.

The host remains owner of polling, Git synchronization, distributed claim, exact-SHA execution and report publication. TestKit owns only the portable routing contract.

## Why this exists

A remote request must not need to hardcode one executor hostname. Multiple compatible executors may observe the same request and the host controller can let the first compatible executor claim it.

Routing and claiming are separate concerns:

```text
request selector
  -> TestKit routing eligibility
  -> host-owned distributed claim
  -> exact SHA + gitlinks
  -> execution
  -> evidence publication
```

Eligibility alone is not an exactly-once guarantee. A host with more than one executor MUST acquire one remote/global claim before execution. Local `flock` or local processed files are insufficient because they do not coordinate different machines.

## Schema 3 — platform selector

A host request using the shared routing contract may use:

```json
{
  "schema": 3,
  "enabled": true,
  "request_id": "structure-audit-example-v1",
  "platform": "linux",
  "profile": "suite",
  "suite": "structure_audit_example"
}
```

`target` is forbidden in schema 3.

Supported platform selectors:

```text
any
linux
windows
macos
```

`platform=any` means any executor supported by the host controller may compete for the request. A platform-specific selector restricts the candidate set without naming a machine.

Additional host-owned fields such as `profile`, `suite` or validated non-secret `parameters` are outside the router's execution contract. The router does not execute or interpret them.

## Legacy compatibility

Schema 1 and schema 2 requests that contain an exact `target` remain routable as legacy target-specific requests. This allows hosts to migrate without invalidating historical requests.

New requests should prefer schema 3 unless a host explicitly requires a legacy exact-target workflow.

## Entry point

```bash
export TESTKIT_PROJECT_ROOT=/absolute/path/to/host
export TESTKIT_REMOTE_PLATFORM=linux   # optional override; otherwise detected

bash ./bin/testkit-remote-request-route \
  config/remote-test-request.json \
  --json
```

The machine response uses:

```text
testkit.remote-request-route.v1
```

Relevant statuses:

```text
ELIGIBLE
PLATFORM_MISMATCH
TARGET_MISMATCH
DISABLED
ERROR
```

A mismatch is not an execution failure and exits `0`. Invalid contracts fail closed with exit `2`.

## Distributed claim invariant

For schema 3, the host controller MUST claim `request_id` before starting any mutable preparation or test execution.

A safe claim implementation must provide these invariants:

1. only one executor can win a given `request_id`;
2. the claim does not modify the requested `main` checkpoint, so `tested_sha` remains stable;
3. the winner identity is evidence, not routing input;
4. losing executors do not execute the request;
5. reusing the same `request_id` for another checkpoint is rejected;
6. if the winner dies before terminal evidence, automatic failover is not assumed; submit a new `request_id` unless the host implements an explicit lease protocol.

One acceptable host implementation is an immutable Git ref/branch per request, created by competing executors with a non-force push. The first push wins; later non-fast-forward/create races lose. TestKit deliberately does not own that Git topology because polling and publication remain host responsibilities.

## Security

The routing request does not carry:

- commands;
- arbitrary arguments;
- secrets or credentials;
- executable paths;
- environment values;
- a mutable target SHA.

Platform selection is an eligibility filter only. Risk admission, network/hardware opt-ins and allowlisted suite execution remain separate contracts.
