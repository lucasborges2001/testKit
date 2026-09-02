# Public host suite entrypoint

`bin/testkit-suite-config` is the public TestKit entrypoint for host-owned declarative suite catalogs.

## Ownership

TestKit owns execution semantics. The host owns the catalog, tests, working directories, domain acceptance rules and cleanup implementation.

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

Arguments are forwarded to the declarative runner after TestKit applies the optional suite-policy preflight. The existing `runSuiteConfig` exit contract remains authoritative:

- `0`: no required suite failed;
- `1`: at least one required suite failed;
- `2`: invalid invocation/configuration, suite-policy rejection or requested machine-result publication failure.

`--result-json` keeps the same schema and atomic-write semantics documented in `docs/RUN_SUITE_CONFIG.md`.

## Agent-safe suite policy

Catalogs may opt in to policy version 1:

```php
return [
    'suite_policy_version' => 1,
    'suites' => [
        [
            'key' => 'runtime_readonly',
            'label' => 'runtime read-only',
            'working_directory' => '.',
            'commands' => ['php test/infra/runtime_readonly.php'],
            'required' => true,
            'description' => 'Read-only runtime checks.',
            'risk' => 'safe',
            'requires' => ['php', 'http'],
            'exclusive' => false,
        ],
    ],
];
```

Policy v1 requires every suite to declare one risk value:

```text
safe
  no persistent or disposable mutation is expected

disposable
  mutation is restricted to an isolated disposable runtime

persistent
  the suite may mutate persistent product/runtime state

hardware
  the suite depends on external hardware; hardware mutation semantics remain host-owned
```

`requires` is optional machine-readable metadata and `exclusive` may be used by orchestrators to avoid overlapping executions. TestKit validates their types but does not infer domain meaning from them.

### Persistent opt-in

Any selected suite graph containing `risk=persistent` is rejected unless the caller explicitly supplies:

```bash
--allow-persistent
```

Example:

```bash
/path/to/Base/testkit/bin/testkit-suite-config \
  config/testkit-suites.php notification_resend_e2e \
  --allow-persistent \
  --result-json .testkit/exchange/resend.json
```

The opt-in is selection-scoped. It is not inferred from CI, agent mode, environment variables or suite names.

### Persistent cleanup declaration

A persistent command suite must declare self-owned guaranteed cleanup:

```php
'cleanup' => [
    'strategy' => 'self',
    'guaranteed' => true,
    'description' => 'The test releases its fixture in finally/trap and fails if cleanup fails.',
],
```

Policy v1 deliberately supports only `strategy=self`. TestKit validates the declaration but does not execute domain cleanup commands. The host test must implement cleanup in `finally`, `trap` or an equivalent guaranteed path and must surface cleanup failure as a failing suite.

This keeps domain behavior in the consumer while preventing an agent from treating a persistent E2E as an ordinary safe suite.

Catalogs without `suite_policy_version` remain backward compatible and are not assigned an implicit risk classification.

## Project root resolution

- If `TESTKIT_PROJECT_ROOT` is set, it must identify an existing directory and is used as the host root.
- Otherwise the current working directory is used.
- The entrypoint exports the normalized root to child commands.

For non-interactive automation, always set `TESTKIT_PROJECT_ROOT` explicitly rather than relying on the working directory.

## Boundary

This command does not own Git fetch/checkout, GitHub requests, systemd, publication of host reports or host-specific Docker lifecycle. Those remain orchestration responsibilities outside TestKit.

Domain fixtures, authentication flows, business assertions and cleanup implementation remain owned by the host repository.

## Verification

Reference smokes:

```bash
bash tests/framework/test_suite_config_entrypoint.sh
bash tests/framework/test_suite_config_risk_policy.sh
```

The entrypoint smoke verifies list mode, successful execution, caller-owned JSON evidence, project-root propagation and exit `2` for a missing catalog. The risk-policy smoke verifies backward compatibility, persistent denial without opt-in, explicit opt-in, composite propagation and mandatory persistent cleanup metadata.
