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

The default output mode is `live`, which preserves the previous behavior: child stdout/stderr is streamed directly and successful suites print `OK <key>`.

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

In `failures` mode TestKit captures each command output. Successful output is discarded. When a command fails, TestKit prints the suite key, command, exit code and the captured stdout/stderr. The selected suite finishes with an aggregate summary containing suite counts, command counts and elapsed time.

A suite can override the root policy with `output => 'live'` or `output => 'failures'`.

## Native suite composition

A suite declares exactly one execution source: `commands` or `suites`.

```php
return [
    'output' => 'failures',
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
            'key' => 'security',
            'label' => 'security',
            'working_directory' => '.',
            'commands' => ['php test/security.php'],
            'required' => true,
            'description' => 'Security checks.',
        ],
        [
            'key' => 'all',
            'label' => 'all host checks',
            'working_directory' => '.',
            'suites' => ['structure', 'security'],
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

Optional suite failures remain visible in the aggregate counts but do not make the selected aggregate fail solely because they are optional.
