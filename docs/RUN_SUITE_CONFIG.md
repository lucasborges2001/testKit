# Declarative suite runner

`runners/runSuiteConfig.php` executes suites declared by a host project without embedding host-specific test logic in TestKit.

## Minimal config

```php
<?php
return [
    'suites' => [
        [
            'key' => 'unit',
            'label' => 'unit tests',
            'working_directory' => '.',
            'commands' => ['php test/unit.php'],
            'required' => true,
            'description' => 'Host unit checks.',
        ],
    ],
];
```

The default output mode is `live`, which streams child stdout/stderr directly and prints `OK <key>` for successful suites.

## Failure-only output

Hosts that want quiet successful runs can opt in at the config root:

```php
return [
    'output' => 'failures',
    'suites' => [
        // ...
    ],
];
```

In `failures` mode TestKit captures each command output. By default stdout and stderr from successful commands are discarded. When a command fails, TestKit prints the suite key, command, exit code and captured stdout/stderr. The selected suite finishes with an aggregate summary containing suite counts, command counts and elapsed time.

A suite can override the root policy with `output => 'live'` or `output => 'failures'`.

## Successful stderr

To keep successful runs compact while preserving warnings written to stderr:

```php
return [
    'output' => 'failures',
    'success_stderr' => 'show',
    'suites' => [
        // ...
    ],
];
```

`success_stderr` accepts:

- `hide` (default): successful stdout and stderr stay hidden in captured output;
- `show`: successful stdout stays hidden and successful stderr is emitted after the command finishes.

This option is backward compatible by default and does not alter command exit codes, failure reporting, suite counts or summaries. In `live` mode child stderr is already streamed, so no duplicate output is added.

A suite may override the inherited policy with `success_stderr => 'hide'` or `success_stderr => 'show'`. Composite overrides are inherited by child suites unless a child declares its own value.

## Child process environment

Commands executed by the declarative runner inherit the current process environment, including values such as `PATH`, CI variables and host-provided runtime settings. TestKit then sets `TESTKIT_PROJECT_ROOT` explicitly to the resolved host root for the child command.

The runner does not rely only on PHP's `$_ENV`, because that superglobal may be empty when `variables_order` omits `E`. Environment inheritance therefore remains stable for child PHP and Python processes even in that configuration.

This restores normal child-process semantics; it does not add new environment variables or print inherited values.

## Native suite composition

A suite declares exactly one execution source: `commands` or `suites`.

```php
return [
    'output' => 'failures',
    'success_stderr' => 'show',
    'suites' => [
        [
            'key' => 'structure',
            'label' => 'structure',
            'working_directory' => '.',
            'commands' => ['php test/structure.php'],
            'required' => true,
            'description' => 'Structure checks.',
        ],
        [
            'key' => 'all',
            'label' => 'all host checks',
            'working_directory' => '.',
            'suites' => ['structure'],
            'required' => true,
            'description' => 'Aggregate host regression gate.',
            'fail_fast' => false,
        ],
    ],
];
```

Composition is resolved by TestKit itself; hosts do not need to invoke their TestKit wrapper recursively. Invalid child references and composition cycles fail explicitly.

## Exit contract

- exit `0`: no required suite failed;
- exit `1`: at least one required suite failed;
- exit `2`: invalid CLI arguments or invalid suite configuration.

Optional suite failures remain visible in aggregate counts but do not make the selected aggregate fail solely because they are optional.
