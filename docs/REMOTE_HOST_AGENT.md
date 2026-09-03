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
- execution through `testkit-host-agent`;
- canonical `.testkit/reports` evidence.

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

Only these five fields are accepted. A request cannot carry commands, environment values, credentials, URLs, ports or addresses.

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

## Windows / PowerShell entrypoint

Windows hosts that already consume TestKit through Docker should use:

```powershell
$env:TESTKIT_PROJECT_ROOT = 'C:\dev\host-project'

.\submodules\Base\testkit\bin\testkit-remote-host-agent.ps1 `
  config/testkit-suites-remote.php `
  config/remote-test-request.json `
  -Target host-executor
```

The PowerShell bridge does **not** require PHP or Python installed on Windows. It delegates to `bin/testkit.ps1`, which executes `runRemoteHostAgent.php` inside the normal TestKit container with the host project mounted at `/workspace/project`.

Host suite commands selected by this mode therefore execute **inside the TestKit container**. They must not recursively invoke `bin/testkit`, `bin/testkit.ps1`, `docker compose`, or another TestKit container. A host that wants to execute a native TestKit selection should call `/workspace/testkit/runTest.php` directly from its allowlisted suite command.

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

This bridge does not fetch Git, update submodules, reset a checkout, publish commits or infer a suite. Those responsibilities remain host-owned so repositories can choose their own checkpoint and publication policy without TestKit owning application topology.
