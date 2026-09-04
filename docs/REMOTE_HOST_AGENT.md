# Remote host agent compatibility

`testkit-remote-host-agent` is the reusable bridge between a host-owned remote request and the existing `testkit-host-agent` suite executor.

The host remains owner of:

- `config/remote-test-request.json` (or equivalent path);
- the declarative suite catalog;
- polling/fetch/checkpoint selection;
- git/submodule synchronization;
- publication of repository-level reports;
- domain fixtures and assertions.

TestKit owns:

- request schema validation;
- suite-key selection without arbitrary commands;
- `required=true` admission;
- local target matching;
- risk/requirement gates;
- container execution through `testkit-host-agent`;
- host-native admission for explicitly declared PowerShell suites;
- canonical evidence boundaries.

## Request v1

```json
{
  "schema": 1,
  "enabled": true,
  "request_id": "host-security-r1",
  "target": "host-executor",
  "suite": "plc_security"
}
```

Only these five fields are accepted. A request cannot carry commands, environment values, credentials, URLs, ports, addresses, script paths or result paths.

## Linux/macOS entrypoint

```bash
export TESTKIT_PROJECT_ROOT=/absolute/path/to/host
export TESTKIT_REMOTE_TARGET=host-executor

./bin/testkit-remote-host-agent \
  config/testkit-suites-remote.php \
  config/remote-test-request.json \
  --json
```

This entrypoint expects the native PHP/Bash runtime required by the TestKit installation.

## Windows / container-backed PowerShell entrypoint

Windows hosts that consume ordinary TestKit suites through Docker should use:

```powershell
$env:TESTKIT_PROJECT_ROOT = 'C:\dev\host-project'

.\submodules\Base\testkit\bin\testkit-remote-host-agent.ps1 `
  config/testkit-suites-remote.php `
  config/remote-test-request.json `
  -Target host-executor
```

The PowerShell bridge does **not** require PHP or Python installed on Windows. It delegates to `bin/testkit.ps1`, which executes `runRemoteHostAgentCompat.php` inside the normal TestKit container with the host project mounted at `/workspace/project`. The compatibility runner normalizes the current `testkit-host-agent` result envelope and delegates request/risk execution to `runRemoteHostAgent.php`.

Host suite commands selected by this mode therefore execute **inside the TestKit container**. They must not recursively invoke `bin/testkit`, `bin/testkit.ps1`, `docker compose`, or another TestKit container. A host that wants to execute a native TestKit selection should call `/workspace/testkit/runTest.php` directly from its allowlisted suite command.

## Windows host-native entrypoint

A suite that must execute software installed only on the Windows host, such as an IDE, may declare:

```php
[
    'key' => 'native_build',
    'required' => true,
    'risk' => 'disposable',
    'requires' => ['windows'],
    'execution_backend' => 'host_native',
    'host_native' => [
        'kind' => 'powershell',
        'script' => 'scripts/build-native.ps1',
        'result_file' => '.testkit/native-build-result.json',
    ],
]
```

The request still selects only `suite=native_build`. The script and result paths come exclusively from the versioned host catalog and must be relative project paths without traversal. The current v1 host-native backend accepts only `kind=powershell`.

Execute it with:

```powershell
$env:TESTKIT_PROJECT_ROOT = 'C:\dev\host-project'

.\submodules\Base\testkit\bin\testkit-remote-host-native-agent.ps1 `
  config/testkit-suites.php `
  config/remote-test-request.json `
  -Target windows-executor `
  -AllowDisposable
```

The host-native bridge first runs `runRemoteHostAgent.php --admit-only` inside the normal TestKit container. Only after schema, target, suite, risk and requirement admission succeeds does it resolve the allowlisted PowerShell path below `TESTKIT_PROJECT_ROOT` and execute it in a separate PowerShell process. It deletes any stale result file first and accepts evidence only from the declared JSON result file.

The bridge does not fetch Git, update submodules, infer commands, evaluate remote text, or pass arbitrary arguments from the request. Full process stdout/stderr remain local; the canonical envelope exposes the declared evidence plus only a boolean indicating whether stderr was present.

A host-native suite cannot execute through the ordinary container runner. `runRemoteHostAgent.php` fails closed with `host_native_requires_bridge` unless called with `--admit-only` by the native bridge.

## Admission

Default admission is deliberately narrow: only `risk=safe` suites that do not declare `requires=network` can run.

Additional local opt-ins are executor-side and never come from the request:

```text
--allow-disposable / -AllowDisposable
--allow-network    / -AllowNetwork
--allow-persistent / -AllowPersistent
--allow-hardware   / -AllowHardware
```

`allow-network` only admits a suite whose host catalog already declares `requires => ['network']`; it does not add a target or endpoint to the request.

## Boundary

These bridges do not fetch Git, update submodules, reset a checkout, publish commits or infer a suite. Those responsibilities remain host-owned so repositories can choose their own checkpoint and publication policy without TestKit owning application topology.
