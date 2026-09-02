# Environment contract

`bin/testkit-env-contract` validates host-owned environment requirements without treating `.env` files as PHP INI and without printing environment values.

The host owns the expected keys and non-secret expected values. TestKit owns parsing, redaction-safe assertions, exit semantics and optional machine evidence.

## Public entrypoint

```bash
export TESTKIT_PROJECT_ROOT=/absolute/path/to/host
/path/to/Base/testkit/bin/testkit-env-contract \
  config/runtime-env.php \
  --result-json .testkit/exchange/runtime-env.json
```

Through Base consumers should use the Base facade command documented by Base rather than reaching into `Base/testkit` directly.

## Contract file

Example file-backed contract:

```php
<?php
return [
    'source' => [
        'type' => 'file',
        'path' => '.env',
    ],
    'checks' => [
        ['key' => 'APP_ENV', 'assert' => 'equals', 'value' => 'local'],
        ['key' => 'SMTP_HOST', 'assert' => 'one_of', 'values' => ['mailpit', 'smtp.example.test']],
        ['key' => 'APP_SECRET', 'assert' => 'present', 'sensitive' => true],
        ['key' => 'LEGACY_TOKEN', 'assert' => 'absent', 'sensitive' => true],
    ],
];
```

Process-environment contract:

```php
return [
    'source' => ['type' => 'process'],
    'checks' => [
        ['key' => 'APP_ENV', 'assert' => 'equals', 'value' => 'test'],
    ],
];
```

Supported assertions:

- `present`: key exists and is non-empty;
- `absent`: key is not defined;
- `equals`: exact string equality;
- `one_of`: exact membership in a non-empty string list.

A check marked `sensitive=true` may only use `present` or `absent`. This prevents consumers from storing expected secret material inside the contract itself.

## Dotenv parsing

The file parser is intentionally not `parse_ini_file()`. It accepts ordinary dotenv/Compose-style `KEY=value` records, including values containing parentheses or additional `=` characters, quoted values, `export KEY=value`, blank lines and comments.

The contract and env source must resolve inside `TESTKIT_PROJECT_ROOT`. Result JSON must also be written under the project root.

## Output and redaction

Console output contains only:

```text
PASS KEY assertion
FAIL KEY assertion
```

Actual and expected values are never emitted by this command. Machine evidence schema 1 contains source identity, status, summary and per-key assertion status, but no environment values.

Exit codes:

- `0`: every assertion passed;
- `1`: valid contract executed and at least one assertion failed, or the selected env source could not be read;
- `2`: invalid invocation or invalid contract.

## Boundary

TestKit does not decide which Locker, SMTP, database or production settings are correct. Those expectations remain in the consuming repository. TestKit only provides a reusable, redaction-safe validation primitive.

## Verification

```bash
bash tests/framework/test_env_contract.sh
```

The smoke covers parentheses, embedded `=`, quoting, `export`, process environment checks, machine JSON, sensitive-key restrictions and secret non-disclosure.
