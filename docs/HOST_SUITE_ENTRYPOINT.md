# Public host suite entrypoint

`bin/testkit-suite-config` is the public TestKit entrypoint for host-owned declarative suite catalogs.

## Ownership

TestKit owns execution semantics. The host owns the catalog, tests, working directories and domain acceptance rules.

Consumers should call this entrypoint instead of depending directly on `runners/runSuiteConfig.php`.

## Contract

Set the project under test explicitly for automation:

```bash
export TESTKIT_PROJECT_ROOT=/absolute/path/to/host
```

Then run a host-owned catalog:

```bash
/path/to/Base/testkit/bin/testkit-suite-config config/testkit-suites.php all
```

List suites:

```bash
/path/to/Base/testkit/bin/testkit-suite-config config/testkit-suites.php --list
```

Request caller-owned machine evidence:

```bash
/path/to/Base/testkit/bin/testkit-suite-config \
  config/testkit-suites.php all \
  --result-json .testkit/exchange/all.json
```

Arguments after the entrypoint are forwarded unchanged to the declarative runner. Therefore the existing `runSuiteConfig` exit contract remains authoritative:

- `0`: no required suite failed;
- `1`: at least one required suite failed;
- `2`: invalid invocation/configuration or requested machine-result publication failure.

`--result-json` keeps the same schema and atomic-write semantics documented in `docs/RUN_SUITE_CONFIG.md`.

## Project root resolution

- If `TESTKIT_PROJECT_ROOT` is set, it must identify an existing directory and is used as the host root.
- Otherwise the current working directory is used.
- The entrypoint exports the normalized root to child commands.

For non-interactive automation, always set `TESTKIT_PROJECT_ROOT` explicitly rather than relying on the working directory.

## Boundary

This command does not own Git fetch/checkout, GitHub requests, systemd, publication of host reports or host-specific Docker lifecycle. Those remain orchestration responsibilities outside TestKit.

## Verification

Reference smoke:

```bash
bash tests/framework/test_suite_config_entrypoint.sh
```

The smoke verifies list mode, successful execution, caller-owned JSON evidence, project-root propagation and exit `2` for a missing catalog.
