# Executor capabilities for remote host-native tests

Status: public Bash bridge contract for remote executor preflight.

## Ownership

TestKit owns detection and bounded evidence for generic executor capabilities. Hosts remain responsible for their own business fixtures, application configuration, endpoints, credentials, cleanup and product assertions.

The capability probe never repairs an executor, changes Docker state, reads `.env` values or accepts commands from a remote request.

## Public entrypoint

From the TestKit repository, or through a host's pinned TestKit submodule:

```bash
bash bin/testkit-remote-host-native-agent capabilities --json
```

Require concrete capabilities when a host needs an admission-style preflight:

```bash
bash bin/testkit-remote-host-native-agent capabilities \
  --require bash \
  --require git \
  --require docker \
  --require docker-daemon \
  --require docker-compose \
  --require python3 \
  --require flock \
  --json
```

The command is local-executor input. Remote request payloads still cannot provide commands, paths, credentials, endpoints or capability overrides.

## Contract

Schema:

```text
testkit.executor-capabilities.v1
```

A successful required set returns exit `0` and `status=PASS`. Missing required capabilities return exit `1` and `status=BLOCKED`. Unsupported requirements or options return exit `2` and `status=ERROR`.

Example shape:

```json
{
  "schema": "testkit.executor-capabilities.v1",
  "status": "PASS",
  "platform": "linux",
  "required": ["bash", "docker", "docker-daemon"],
  "checks": {
    "bash": "PASS",
    "docker": "PASS",
    "docker-daemon": "PASS"
  },
  "missing": []
}
```

`checks` is an inventory. Optional failed checks do not block a request unless they were included in `required`.

## Current capability vocabulary

- `linux`, `macos`, `windows`
- `bash`
- `git`
- `php`
- `python3`
- `realpath`
- `mktemp`
- `flock`
- `sha256sum`
- `docker`
- `docker-daemon`
- `docker-compose`
- `writable-tmp`

The normal Bash host-native bridge requires its own runtime prerequisites before Docker admission and publishes the same capability envelope as `executor_capabilities` on host-native results.

## Network boundary

`network` is intentionally not reported as PASS by this probe. Connectivity is endpoint-specific and cannot be proven generically without inventing a target.

Remote suites that declare `requires => ['network']` still need the executor-local `--allow-network` admission flag. Actual connectivity failures are then observed against the real Git, registry, service or product endpoint during sync/execution and must remain distinguishable from product failures.

## Safety

The probe is read-only except for a temporary write/delete used by `writable-tmp`. It does not:

- modify persistent Docker state;
- start or stop containers;
- pull images;
- alter Git refs or working trees;
- read application secrets;
- make arbitrary network requests;
- evaluate remote text.

The Bash bridge continues to reject arbitrary request commands and unsafe project paths.

## Verification

```bash
bash -n lib/bash/executor_capabilities.sh
bash -n bin/testkit-remote-host-native-agent
bash tests/framework/test_executor_capabilities.sh
php tests/framework/test_remote_host_native_bash_contract.php
make self-test
```

PowerShell host-native capability parity is not part of this slice. Existing PowerShell remote execution remains unchanged and must not be treated as covered by this Bash capability contract.
