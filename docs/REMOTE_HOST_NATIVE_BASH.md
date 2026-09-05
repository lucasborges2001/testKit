# Remote host-native Bash bridge

## Purpose

`bin/testkit-remote-host-native-agent` extends the remote host-agent contract to Linux/macOS executors that must run an allowlisted Bash script on the host rather than inside the TestKit container.

Use this backend only when the selected host suite needs host-owned facilities that cannot be nested inside the TestKit container, such as a Docker Compose deployment controlled by the host.

## Ownership boundary

TestKit owns:

- request schema validation;
- suite-key selection;
- risk admission;
- path confinement;
- the host-native Bash bridge;
- capture of native stdout/stderr metadata;
- acceptance of the declared JSON result file as canonical evidence.

The consuming host owns:

- Git synchronization and checkpoint selection;
- `config/remote-test-request.json`;
- the suite catalog;
- the allowlisted Bash script;
- domain fixtures/assertions and cleanup;
- publication of repository-level reports.

The remote request still cannot provide a command, environment value, script path, URL or credential.

## Catalog

```php
[
    'key' => 'docker_runtime_gate',
    'required' => true,
    'risk' => 'disposable',
    'requires' => ['linux', 'docker'],
    'execution_backend' => 'host_native',
    'host_native' => [
        'kind' => 'bash',
        'script' => 'scripts/runners/remote-runtime-gate.sh',
        'result_file' => '.testkit/remote-runtime-gate.json',
    ],
]
```

`kind=bash` requires a project-relative `.sh` script. `result_file` remains a project-relative `.json` path. Parent traversal, absolute paths and paths outside `TESTKIT_PROJECT_ROOT` are rejected.

## Invocation

```bash
export TESTKIT_PROJECT_ROOT=/absolute/path/to/host
export TESTKIT_REMOTE_TARGET=host-linux-main

/path/to/testkit/bin/testkit-remote-host-native-agent \
  config/testkit-suites-remote.php \
  config/remote-test-request.json \
  --allow-disposable \
  --json
```

Admission occurs first inside the normal TestKit container with `runRemoteHostAgent.php --admit-only`. Only an admitted `execution_backend=host_native`, `kind=bash` suite is executed on the host.

The bridge removes stale declared results, executes the allowlisted script synchronously with `/usr/bin/env bash`, captures stdout/stderr under `.testkit/remote-host-native/`, and returns PASS only when both the native exit code is zero and the declared JSON evidence has `status=PASS`.

## Security invariants

- no arbitrary command field;
- no `eval` or `bash -c` execution of remote text;
- no Git fetch/pull/reset ownership inside TestKit;
- no executable download;
- no path traversal outside the host project;
- disposable/network/persistent/hardware risk still requires the existing local opt-ins.

## Compatibility

The existing PowerShell host-native bridge and ordinary container backend are unchanged. Hosts using `kind=powershell` continue to use `bin/testkit-remote-host-native-agent.ps1`.

Reference contract:

```bash
php tests/framework/test_remote_host_native_bash_contract.php
```
