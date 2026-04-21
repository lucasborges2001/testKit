<?php
declare(strict_types=1);

namespace Testkit\Core\Config;

final class ConfigSchema
{
    /**
     * @return array<string,mixed>
     */
    public static function inspectPayload(): array
    {
        return [
            'schema_version' => 2,
            'commands' => [
                [
                    'command' => 'php runTest.php --help',
                    'purpose' => 'mostrar ayuda del runner',
                ],
                [
                    'command' => 'php runTest.php <target> --list',
                    'purpose' => 'listar la selección efectiva sin ejecutar tests',
                ],
                [
                    'command' => 'php scripts/inspect.php config-schema',
                    'purpose' => 'ver el esquema soportado en formato texto',
                ],
                [
                    'command' => 'php scripts/inspect.php config-schema --json',
                    'purpose' => 'serializar el esquema soportado',
                ],
            ],
            'targets' => [
                'all',
                'back',
                'front',
                'public_html',
                'back-php',
                'back-py',
                'back-python',
                'python',
                'py',
                'front-php',
                'front-js',
                'php',
                'js',
                'smoke',
                'perf',
                'stress',
                'contract',
                'critical',
                'slow',
                'migration-contract',
                'migration',
                'migrations',
            ],
            'environment' => [
                self::env(
                    key: 'TEST_SCOPE',
                    type: 'string',
                    default: 'all',
                    validValues: ['all', 'unit', 'integration', 'e2e'],
                    appliesTo: ['suite', 'meta'],
                    notes: [
                        'Se usa para filtrar tests por scope visible.',
                        'Si no se declara, el runner cae a all.',
                    ]
                ),
                self::env(
                    key: 'TEST_CATEGORY',
                    type: 'string',
                    default: 'all',
                    validValues: ['all', 'smoke', 'perf', 'stress', 'contract', 'critical', 'slow'],
                    appliesTo: ['suite', 'meta'],
                    notes: [
                        'Los category targets pueden inyectarla automáticamente si no está definida.',
                        'Una contradicción visible entre target y TEST_CATEGORY debería verse en doctor.',
                    ]
                ),
                self::env(
                    key: 'TEST_MATCH',
                    type: 'string',
                    default: '',
                    validValues: [],
                    appliesTo: ['suite', 'meta'],
                    notes: [
                        'Filtra la selección efectiva.',
                        'Un rerun sugerido suele materializarse vía TEST_MATCH.',
                    ]
                ),
                self::env(
                    key: 'TEST_LIST',
                    type: 'bool',
                    default: false,
                    validValues: ['1', '0', 'true', 'false', 'yes', 'no', 'on', 'off'],
                    appliesTo: ['suite'],
                    notes: [
                        'runTest.php --list fuerza TEST_LIST=1 para esa corrida.',
                        'Valores bool inválidos deben quedar visibles vía warnings.',
                    ]
                ),
                self::env(
                    key: 'TEST_FAIL_FAST',
                    type: 'bool',
                    default: true,
                    validValues: ['1', '0', 'true', 'false', 'yes', 'no', 'on', 'off'],
                    appliesTo: ['suite'],
                    notes: [
                        'Controla fail-fast intra-suite.',
                    ]
                ),
                self::env(
                    key: 'TEST_META_FAIL_FAST',
                    type: 'bool',
                    default: false,
                    validValues: ['1', '0', 'true', 'false', 'yes', 'no', 'on', 'off'],
                    appliesTo: ['meta'],
                    notes: [
                        'Controla fail-fast del agregado meta.',
                    ]
                ),
                self::env(
                    key: 'TEST_CHILD_FAIL_FAST',
                    type: 'bool',
                    default: false,
                    validValues: ['1', '0', 'true', 'false', 'yes', 'no', 'on', 'off'],
                    appliesTo: ['meta'],
                    notes: [
                        'Meta puede reescribir TEST_FAIL_FAST para child suites.',
                    ]
                ),
                self::env(
                    key: 'TEST_JOBS',
                    type: 'int',
                    default: 1,
                    validValues: ['integer >= 1'],
                    appliesTo: ['suite', 'meta'],
                    notes: [
                        'TEST_JOBS>1 sin per_worker es una ruta de riesgo visible.',
                        'Enteros inválidos deben quedar visibles vía warnings.',
                    ]
                ),
                self::env(
                    key: 'TEST_DB_STRATEGY',
                    type: 'string',
                    default: 'shared',
                    validValues: ['shared', 'per_worker'],
                    appliesTo: ['suite', 'meta'],
                    notes: [
                        'clean no es un modo operativo soportado.',
                        'migration-contract exige shared.',
                    ]
                ),
                self::env(
                    key: 'TEST_REQUIRE_TESTS',
                    type: 'bool',
                    default: false,
                    validValues: ['1', '0', 'true', 'false', 'yes', 'no', 'on', 'off'],
                    appliesTo: ['suite'],
                    notes: [
                        'Hace visible que selección vacía no es aceptable para esa corrida.',
                    ]
                ),
                self::env(
                    key: 'TEST_COVERAGE',
                    type: 'bool',
                    default: false,
                    validValues: ['1', '0', 'true', 'false', 'yes', 'no', 'on', 'off'],
                    appliesTo: ['suite'],
                    notes: [
                        'Habilita cobertura donde la suite/lenguaje la soporte.',
                    ]
                ),
                self::env(
                    key: 'TEST_COVERAGE_FORMAT',
                    type: 'string',
                    default: 'lcov',
                    validValues: ['lcov', 'json', 'both'],
                    appliesTo: ['suite'],
                    notes: [
                        'Se usa solo cuando TEST_COVERAGE está activo.',
                    ]
                ),
                self::env(
                    key: 'TEST_COVERAGE_DIR',
                    type: 'string',
                    default: '',
                    validValues: [],
                    appliesTo: ['suite'],
                    notes: [
                        'Si está presente, redefine el root de artifacts de coverage por suite.',
                    ]
                ),
                self::env(
                    key: 'TEST_BASELINE_MODE',
                    type: 'string',
                    default: '',
                    validValues: ['snapshot', 'fresh', 'reuse'],
                    appliesTo: ['suite', 'meta'],
                    notes: [
                        'migration-contract exige snapshot.',
                    ]
                ),
                self::env(
                    key: 'TEST_STORE_DRIVER',
                    type: 'string',
                    default: 'mysql',
                    validValues: ['mysql'],
                    appliesTo: ['suite', 'meta'],
                    notes: [
                        'La ruta contractual general sigue cerrada alrededor de MySQL.',
                    ]
                ),
                self::env(
                    key: 'TESTKIT_SKIP_STORE_BOOTSTRAP',
                    type: 'bool',
                    default: false,
                    validValues: ['1', '0', 'true', 'false', 'yes', 'no', 'on', 'off'],
                    appliesTo: ['suite'],
                    notes: [
                        'Útil para cortar bootstrap/store cuando la corrida necesita aislar otra cosa.',
                    ]
                ),
            ],
            'notes' => [
                'Valores bool inválidos y enteros no parseables deben quedar visibles vía warnings y persistirse en reportes.',
                'Targets agregados son válidos, pero no son la primera corrida diagnóstica más nítida.',
                'migration-contract exige snapshot + shared + mysql + TEST_JOBS=1.',
            ],
        ];
    }

    public static function printText(): void
    {
        $payload = self::inspectPayload();

        echo "config-schema\n";
        echo str_repeat('=', 72) . PHP_EOL;
        echo 'schema_version: ' . (string)($payload['schema_version'] ?? '1') . PHP_EOL;

        echo PHP_EOL . 'commands:' . PHP_EOL;
        foreach ((array)($payload['commands'] ?? []) as $command) {
            if (!is_array($command)) {
                continue;
            }
            echo '  - ' . (string)($command['command'] ?? '') . PHP_EOL;
            echo '    purpose: ' . (string)($command['purpose'] ?? '') . PHP_EOL;
        }

        echo PHP_EOL . 'targets:' . PHP_EOL;
        echo '  ' . implode(', ', array_values((array)($payload['targets'] ?? []))) . PHP_EOL;

        echo PHP_EOL . 'environment:' . PHP_EOL;
        foreach ((array)($payload['environment'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            echo '  - key: ' . (string)($entry['key'] ?? '') . PHP_EOL;
            echo '    type: ' . (string)($entry['type'] ?? '') . PHP_EOL;
            echo '    default: ' . self::stringify($entry['default'] ?? null) . PHP_EOL;

            $validValues = array_values((array)($entry['valid_values'] ?? []));
            echo '    valid_values: ' . ($validValues === [] ? '(free-form)' : implode(', ', $validValues)) . PHP_EOL;

            $appliesTo = array_values((array)($entry['applies_to'] ?? []));
            echo '    applies_to: ' . ($appliesTo === [] ? '(unspecified)' : implode(', ', $appliesTo)) . PHP_EOL;

            $notes = array_values((array)($entry['notes'] ?? []));
            if ($notes !== []) {
                echo '    notes:' . PHP_EOL;
                foreach ($notes as $note) {
                    echo '      - ' . (string)$note . PHP_EOL;
                }
            }
        }

        echo PHP_EOL . 'notes:' . PHP_EOL;
        foreach ((array)($payload['notes'] ?? []) as $note) {
            echo '  - ' . (string)$note . PHP_EOL;
        }
    }

    /**
     * @param list<string> $validValues
     * @param list<string> $appliesTo
     * @param list<string> $notes
     * @return array<string,mixed>
     */
    private static function env(
        string $key,
        string $type,
        mixed $default,
        array $validValues,
        array $appliesTo,
        array $notes
    ): array {
        return [
            'key' => $key,
            'type' => $type,
            'default' => $default,
            'valid_values' => array_values($validValues),
            'applies_to' => array_values($appliesTo),
            'notes' => array_values($notes),
        ];
    }

    private static function stringify(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            is_array($value) => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]',
            default => (string)$value,
        };
    }
}
