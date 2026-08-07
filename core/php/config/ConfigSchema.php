<?php
declare(strict_types=1);

namespace Testkit\Core\Config;

final class ConfigSchema
{
    /** @return array<string,mixed> */
    public static function inspectPayload(): array
    {
        return [
            'schema_version' => 6,
            'support_contract_version' => 1,
            'commands' => [
                ['command' => 'php runTest.php --help', 'purpose' => 'mostrar ayuda del runner'],
                ['command' => 'php runTest.php <target> --list', 'purpose' => 'listar la selección efectiva sin ejecutar tests'],
                ['command' => 'php scripts/inspect.php config-schema', 'purpose' => 'ver el esquema soportado en formato texto'],
                ['command' => 'php scripts/inspect.php config-schema --json', 'purpose' => 'serializar el esquema soportado'],
            ],
            'targets' => [
                'all', 'back', 'front', 'infra', 'public_html', 'back-php', 'back-py', 'back-python',
                'python', 'py', 'front-php', 'front-js', 'infra-php', 'http', 'php', 'js', 'smoke', 'perf',
                'stress', 'contract', 'critical', 'security', 'slow', 'migration-contract', 'migration', 'migrations',
            ],
            'support_matrix' => self::supportMatrix(),
            'db_strategies' => [
                'supported' => [
                    'shared' => [
                        'status' => 'supported',
                        'scope' => 'suite',
                        'notes' => [
                            'Ruta simple/secuencial.',
                            'Con tests DB-sensibles y TEST_JOBS>1 debe rechazarse o migrarse a per_worker.',
                        ],
                    ],
                    'per_worker' => [
                        'status' => 'supported_intra_suite',
                        'scope' => 'suite_workers',
                        'top_level_parallel_safe' => false,
                        'notes' => [
                            'Aísla workers dentro de una suite.',
                            'No vuelve seguro lanzar múltiples runners top-level sobre el mismo store base.',
                        ],
                    ],
                ],
                'rejected' => [
                    'clean' => [
                        'status' => 'rejected_not_implemented',
                        'blocking' => true,
                        'notes' => [
                            'Reconocido como valor no implementado.',
                            'No debe aparecer como ruta operativa soportada.',
                        ],
                    ],
                ],
            ],
            'environment' => [
                self::env('TEST_SCOPE', 'string', 'all', ['all', 'unit', 'integration', 'e2e'], ['suite', 'meta'], [
                    'Se usa para filtrar tests por scope visible.',
                    'Si no se declara, el runner cae a all.',
                ]),
                self::env('TEST_CATEGORY', 'string', 'all', ['all', 'smoke', 'perf', 'stress', 'contract', 'critical', 'security', 'slow'], ['suite', 'meta'], [
                    'Los category targets pueden inyectarla automáticamente si no está definida.',
                    'Una contradicción visible entre target y TEST_CATEGORY debería verse en doctor.',
                ]),
                self::env('TEST_MATCH', 'string', '', [], ['suite', 'meta'], [
                    'Filtro legacy por substring. Se mantiene por compatibilidad hacia atrás.',
                    'Precedencia: se usa solo si TEST_MATCH_FILE y TEST_MATCH_LIST no están definidos.',
                ]),
                self::env('TEST_MATCH_LIST', 'csv', '', [], ['suite'], [
                    'Selecciona múltiples tests en una misma corrida usando rutas repo-relative separadas por coma.',
                    'Default exact match contra rel-path descubierto. Puede usar substring con TEST_MATCH_LIST_MODE=substring.',
                    'Precede a TEST_MATCH y queda por debajo de TEST_MATCH_FILE.',
                ]),
                self::env('TEST_MATCH_FILE', 'string', '', [], ['suite'], [
                    'Archivo repo-relative con una ruta de test por línea; ignora líneas vacías y comentarios que empiezan con #.',
                    'Rechaza path traversal con .. y entradas absolutas dentro del archivo.',
                    'Tiene mayor precedencia que TEST_MATCH_LIST y TEST_MATCH.',
                ]),
                self::env('TEST_MATCH_LIST_MODE', 'string', 'exact', ['exact', 'substring'], ['suite'], [
                    'Controla TEST_MATCH_FILE y TEST_MATCH_LIST.',
                    'exact es la política segura por defecto; substring debe declararse explícitamente.',
                    'Alias compatible: TEST_SELECTION_MATCH_MODE.',
                ]),
                self::env('TEST_SELECTION_MATCH_MODE', 'string', 'exact', ['exact', 'substring'], ['suite'], [
                    'Alias legacy de TEST_MATCH_LIST_MODE.',
                    'Se conserva para paquetes previos; configuración nueva debería usar TEST_MATCH_LIST_MODE.',
                ]),
                self::env('TEST_RERUN_FAILED_ISOLATED', 'bool', false, ['1', '0', 'true', 'false', 'yes', 'no', 'on', 'off'], ['suite'], [
                    'Si la corrida batch falla, reejecuta solo los archivos fallidos uno por uno con TEST_JOBS=1.',
                    'Clasifica confirmed_failure, interference_suspected o inconclusive dentro de isolated_rerun.',
                    'No cambia exit code: isolated_rerun.affects_exit_code=false.',
                    'Desactiva coverage durante el rerun aislado para no mezclar artefactos.',
                ]),
                self::env('TEST_ISOLATED_RERUN_ACTIVE', 'bool', false, ['1', '0'], ['internal'], [
                    'Guard interno para impedir rerun aislado recursivo.',
                    'No debe definirse manualmente en corridas normales.',
                ]),
                self::env('TEST_LIST', 'bool', false, ['1', '0', 'true', 'false', 'yes', 'no', 'on', 'off'], ['suite'], [
                    'runTest.php --list fuerza TEST_LIST=1 para esa corrida.',
                    'Valores bool inválidos deben quedar visibles vía warnings.',
                ]),
                self::env('TEST_FAIL_FAST', 'bool', true, ['1', '0', 'true', 'false', 'yes', 'no', 'on', 'off'], ['suite'], [
                    'Controla fail-fast intra-suite.',
                ]),
                self::env('TEST_META_FAIL_FAST', 'bool', false, ['1', '0', 'true', 'false', 'yes', 'no', 'on', 'off'], ['meta'], [
                    'Controla fail-fast del agregado meta.',
                ]),
                self::env('TEST_CHILD_FAIL_FAST', 'bool', false, ['1', '0', 'true', 'false', 'yes', 'no', 'on', 'off'], ['meta'], [
                    'Meta puede reescribir TEST_FAIL_FAST para child suites.',
                ]),
                self::env('TEST_JOBS', 'int', 1, ['integer >= 1'], ['suite', 'meta'], [
                    'TEST_JOBS>1 sin per_worker es una ruta de riesgo visible.',
                    'Enteros inválidos deben quedar visibles vía warnings.',
                ]),
                self::env('TEST_DB_STRATEGY', 'string', 'shared', ['shared', 'per_worker'], ['suite', 'meta'], [
                    'clean no es un modo operativo soportado y debe rechazarse explícitamente.',
                    'per_worker aísla workers dentro de una suite; no habilita múltiples runners top-level.',
                    'migration-contract exige shared.',
                ], ['clean']),
                self::env('TEST_REQUIRE_TESTS', 'bool', false, ['1', '0', 'true', 'false', 'yes', 'no', 'on', 'off'], ['suite'], [
                    'Hace visible que selección vacía no es aceptable para esa corrida.',
                ]),
                self::env('TEST_COVERAGE', 'bool', false, ['1', '0', 'true', 'false', 'yes', 'no', 'on', 'off'], ['suite'], [
                    'Habilita cobertura donde la suite/lenguaje la soporte.',
                ]),
                self::env('TEST_COVERAGE_FORMAT', 'string', 'lcov', ['lcov', 'json', 'both'], ['suite'], [
                    'Se usa solo cuando TEST_COVERAGE está activo.',
                ]),
                self::env('TEST_COVERAGE_ROOT', 'string', '.testkit/coverage', [], ['suite'], [
                    'Root canónico de artifacts de coverage.',
                    'El directorio final se resuelve agregando el suite_id: <root>/<suite_id>.',
                    'Ejemplo: TEST_COVERAGE_ROOT=/tmp/cov produce /tmp/cov/back_php para back_php.',
                ]),
                self::env('TEST_COVERAGE_DIR', 'string', '', [], ['suite'], [
                    'Alias legacy de TEST_COVERAGE_ROOT.',
                    'Se preserva la semántica histórica: funciona como root y el runner agrega el suite_id.',
                    'Preferir TEST_COVERAGE_ROOT en configuración nueva.',
                ]),
                self::env('TEST_COVERAGE_SOURCE_DIRS', 'csv', 'TK_BACK_DIR,TK_PUBLIC_DIR', [], ['suite'], [
                    'Filtra el cálculo real de overall, files, modules, low_files, critical_missing y critical_low.',
                    'Ejemplo: back,public_html mide solo archivos bajo back/ y public_html/.',
                ]),
                self::env('TEST_COVERAGE_EXCLUDE_DIRS', 'csv', 'test,testkit,docker,vendor,logs,storage', [], ['suite'], [
                    'Política centralizada de directorios excluidos de coverage.',
                ]),
                self::env('TEST_COVERAGE_CRITICAL_FILES', 'csv', '', [], ['suite'], [
                    'Patrones fnmatch repo-relativos para marcar archivos críticos.',
                ]),
                self::env('TEST_COVERAGE_CRITICAL_THRESHOLD', 'int', 85, ['integer >= 1'], ['suite'], [
                    'Porcentaje mínimo para que un archivo crítico no figure como critical_low.',
                ]),
                self::env('TEST_COVERAGE_LOW_THRESHOLD', 'int', 70, ['integer >= 1'], ['suite'], [
                    'Porcentaje usado para low_files.',
                ]),
                self::env('TEST_COVERAGE_SUMMARY_TOP', 'int', 10, ['integer >= 0'], ['report'], [
                    'Cantidad máxima de archivos missing/low mostrados por scripts/report.php.',
                ]),
                self::env('TEST_BASELINE_MODE', 'string', 'layered', ['layered', 'snapshot'], ['suite', 'meta'], [
                    'migration-contract exige snapshot.',
                    'snapshot cerrado actualmente solo en la ruta MySQL.',
                ]),
                self::env('TEST_STORE_DRIVER', 'string', null, ['mysql', 'pgsql', 'none'], ['wrapper', 'doctor', 'suite', 'meta'], [
                    'Variable obligatoria y única para seleccionar el store estructural.',
                    'Los valores son exactos: mysql, pgsql o none; no se normalizan aliases ni mayúsculas.',
                    'No se infiere desde DB_DRIVER, TEST_DB_DRIVER, TEST_DB_DSN, credenciales, nombres de DB ni TESTKIT_STACK.',
                    'none declara proyecto sin store runtime; no se exige env DB ni bootstrap estructural.',
                ], ['pg', 'postgres', 'postgresql', 'MYSQL', 'PGSQL']),
                self::env('TESTKIT_STACK', 'csv', 'mysql,redis', ['mysql', 'pg', 'redis', 'influx'], ['wrapper', 'doctor'], [
                    'mysql describe el servicio principal cerrado.',
                    'pg describe infraestructura parcial.',
                    'redis es auxiliar, no lifecycle estructural core.',
                    'influx es auxiliar/perfilado, no store principal.',
                    'si TEST_STORE_DRIVER=none y TESTKIT_STACK no se declara, el stack efectivo queda vacío.',
                ]),
                self::env('TK_INFRA_PHP_TEST_ROOTS', 'csv', 'test/infra', [], ['infra_php'], [
                    'Roots repo-relativos para tests operacionales PHP del host.',
                    'No convierte infra en back funcional ni fuerza bootstrap de store.',
                ]),
                self::env('TK_INFRA_PHP_TEST_PATTERNS', 'csv', '*.test.php', [], ['infra_php'], [
                    'Patrones fnmatch para discovery de infra_php.',
                    'La convención limpia recomendada es *.test.php.',
                ]),
                self::env('TK_INFRA_PHP_TEST_EXCLUDE_ROOTS', 'csv', '', [], ['infra_php'], [
                    'Roots excluidos del discovery multi-root de infra_php.',
                ]),
                self::env('TK_INFRA_PHP_TEST_EXCLUDE_PATTERNS', 'csv', '*/vendor/*,*/node_modules/*,*/_out/*,*/.testkit/*,*/testkit/*', [], ['infra_php'], [
                    'Patrones repo-relativos excluidos de infra_php.',
                ]),
                self::env('TESTKIT_SKIP_STORE_BOOTSTRAP', 'bool', false, ['1', '0', 'true', 'false', 'yes', 'no', 'on', 'off'], ['suite'], [
                    'Útil para cortar bootstrap/store cuando la corrida necesita aislar otra cosa.',
                ]),
            ],
            'notes' => [
                'Valores bool inválidos y enteros no parseables deben quedar visibles vía warnings y persistirse en reportes.',
                'Targets agregados son válidos, pero no son la primera corrida diagnóstica más nítida.',
                'TEST_MATCH_FILE > TEST_MATCH_LIST > TEST_MATCH.',
                'TEST_RERUN_FAILED_ISOLATED no ejecuta concurrencia top-level: reusa la suite actual y corre los fallidos uno por uno.',
                'migration-contract exige snapshot + shared + mysql + TEST_JOBS=1.',
                'TEST_STORE_DRIVER es obligatorio y no tiene aliases ni inferencia.',
                'UNKNOWN en doctor/config no es PASS implícito.',
            ],
        ];
    }

    public static function printText(): void
    {
        $payload = self::inspectPayload();

        echo "config-schema\n";
        echo str_repeat('=', 72) . PHP_EOL;
        echo 'schema_version: ' . (string)($payload['schema_version'] ?? '1') . PHP_EOL;
        echo 'support_contract_version: ' . (string)($payload['support_contract_version'] ?? '1') . PHP_EOL;

        echo PHP_EOL . 'support_matrix:' . PHP_EOL;
        foreach ((array)($payload['support_matrix']['engines'] ?? []) as $engine) {
            if (!is_array($engine)) {
                continue;
            }
            echo '  - ' . (string)($engine['name'] ?? '')
                . ' status=' . (string)($engine['status'] ?? '')
                . ' role=' . (string)($engine['role'] ?? '')
                . PHP_EOL;
        }
        foreach ((array)($payload['support_matrix']['services'] ?? []) as $service) {
            if (!is_array($service)) {
                continue;
            }
            echo '  - ' . (string)($service['name'] ?? '')
                . ' status=' . (string)($service['status'] ?? '')
                . ' role=' . (string)($service['role'] ?? '')
                . PHP_EOL;
        }

        echo PHP_EOL . 'db_strategies:' . PHP_EOL;
        foreach ((array)($payload['db_strategies']['supported'] ?? []) as $name => $entry) {
            echo '  - ' . (string)$name . ' status=' . (string)($entry['status'] ?? '') . PHP_EOL;
        }
        foreach ((array)($payload['db_strategies']['rejected'] ?? []) as $name => $entry) {
            echo '  - ' . (string)$name . ' status=' . (string)($entry['status'] ?? '') . PHP_EOL;
        }

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

            $rejectedValues = array_values((array)($entry['rejected_values'] ?? []));
            if ($rejectedValues !== []) {
                echo '    rejected_values: ' . implode(', ', $rejectedValues) . PHP_EOL;
            }

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

    /** @return array<string,mixed> */
    private static function supportMatrix(): array
    {
        return [
            'engines' => [
                [
                    'name' => 'mysql',
                    'status' => 'closed_primary',
                    'role' => 'primary_structural_store',
                    'contract' => [
                        'provision' => true,
                        'reset' => true,
                        'layered_baseline' => true,
                        'snapshot_restore' => true,
                        'per_worker_clone' => true,
                        'migration_contract' => true,
                    ],
                    'limits' => ['requires visible DB env.', 'per_worker is intra-suite only.'],
                ],
                [
                    'name' => 'pgsql',
                    'status' => 'partial_experimental',
                    'role' => 'secondary_partial_store',
                    'contract' => [
                        'provision' => 'basic',
                        'reset' => 'basic',
                        'layered_baseline' => 'not_closed',
                        'snapshot_restore' => false,
                        'per_worker_clone' => false,
                        'migration_contract' => false,
                    ],
                    'limits' => ['No closed snapshot restore contract.', 'No closed clone/per_worker contract.', 'Not equivalent to MySQL.'],
                ],
                [
                    'name' => 'none',
                    'status' => 'no_store',
                    'role' => 'no_structural_store',
                    'contract' => [
                        'provision' => false,
                        'reset' => false,
                        'layered_baseline' => false,
                        'snapshot_restore' => false,
                        'per_worker_clone' => false,
                        'migration_contract' => false,
                    ],
                    'limits' => ['No DB credentials are required.', 'No structural seed/bootstrap lifecycle runs.'],
                ],
            ],
            'services' => [
                [
                    'name' => 'redis',
                    'status' => 'auxiliary',
                    'role' => 'optional_service',
                    'contract' => ['structural_store_lifecycle' => false, 'baseline_participant' => false],
                    'limits' => ['No core PHP seed/bootstrap lifecycle.'],
                ],
                [
                    'name' => 'influx',
                    'status' => 'auxiliary_profiling',
                    'role' => 'profiling_service',
                    'contract' => ['structural_store_lifecycle' => false, 'baseline_participant' => false, 'profiling' => true],
                    'limits' => ['Not a primary store driver.'],
                ],
            ],
        ];
    }

    /**
     * @param list<string> $validValues
     * @param list<string> $appliesTo
     * @param list<string> $notes
     * @param list<string> $rejectedValues
     * @return array<string,mixed>
     */
    private static function env(
        string $key,
        string $type,
        mixed $default,
        array $validValues,
        array $appliesTo,
        array $notes,
        array $rejectedValues = []
    ): array {
        return [
            'key' => $key,
            'type' => $type,
            'default' => $default,
            'valid_values' => array_values($validValues),
            'rejected_values' => array_values($rejectedValues),
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
