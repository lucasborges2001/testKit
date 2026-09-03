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
  "target": "ubuntudev",
  "suite": "plc_security"
}
```

Only these five fields are accepted. A request cannot carry commands, environment values, credentials, URLs, ports or addresses.

## Usage

```bash
export TESTKIT_PROJECT_ROOT=/absolute/path/to/host
export TESTKIT_REMOTE_TARGET=ubuntudev

./bin/testkit-remote-host-agent \
  config/testkit-suites-remote.php \
  config/remote-test-request.json \
  --json
```

Default admission is deliberately narrow: only `risk=safe` suites that do not declare `requires=network` can run.

Additional local opt-ins are executor-side and never come from the request:

```text
--allow-disposable
--allow-network
--allow-persistent
--allow-hardware
```

`--allow-network` only admits a suite whose host catalog already declares `requires => ['network']`; it does not add a target or endpoint to the request.

## Boundary

This bridge does not fetch Git, update submodules, reset a checkout, publish commits or infer a suite. Those responsibilities remain host-owned so repositories can choose their own checkpoint and publication policy without TestKit owning application topology.
