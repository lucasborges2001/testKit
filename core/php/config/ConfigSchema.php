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
            'ok' => true,
            'command' => 'config-schema',
            'schema_version' => 1,
            'sections' => [
                [
                    'name' => 'runner',
                    'description' => 'Variables de entorno del runner PHP para selección, ejecución y reporting.',
                    'entries' => self::runnerEntries(),
                ],
                [
                    'name' => 'doctor',
                    'description' => 'Variables y opciones del doctor del wrapper.',
                    'entries' => self::doctorEntries(),
                ],
                [
                    'name' => 'wrapper',
                    'description' => 'Variables del wrapper bash/PowerShell y resolución de runtime.',
                    'entries' => self::wrapperEntries(),
                ],
            ],
        ];
    }

    public static function printText(): void
    {
        $payload = self::inspectPayload();
        echo "inspect config-schema" . PHP_EOL;
        echo str_repeat('=', 72) . PHP_EOL;

        foreach ((array)($payload['sections'] ?? []) as $section) {
            if (!is_array($section)) {
                continue;
            }
            echo '[' . strtoupper((string)($section['name'] ?? 'section')) . ']' . PHP_EOL;
            $description = trim((string)($section['description'] ?? ''));
            if ($description !== '') {
                echo $description . PHP_EOL;
            }

            foreach ((array)($section['entries'] ?? []) as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                echo '- ' . (string)($entry['key'] ?? '') . PHP_EOL;
                echo '  type: ' . (string)($entry['type'] ?? '') . PHP_EOL;
                echo '  default: ' . (string)($entry['default'] ?? '') . PHP_EOL;
                $allowed = (array)($entry['allowed'] ?? []);
                if ($allowed !== []) {
                    echo '  allowed: ' . implode(', ', array_map('strval', $allowed)) . PHP_EOL;
                }
                echo '  purpose: ' . (string)($entry['purpose'] ?? '') . PHP_EOL;
                $example = trim((string)($entry['example'] ?? ''));
                if ($example !== '') {
                    echo '  example: ' . $example . PHP_EOL;
                }
            }
            echo PHP_EOL;
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function runnerEntries(): array
    {
        return [
            self::entry('TEST_SCOPE', 'string', 'all', ['all', 'unit', 'integration', 'smoke', 'perf', 'stress'], 'Filtra el scope lógico de tests.', 'TEST_SCOPE=integration'),
            self::entry('TEST_CATEGORY', 'string', 'all', ['all', 'critical', 'contract', 'smoke', 'perf', 'stress', 'slow'], 'Filtra categorías/tags de test.', 'TEST_CATEGORY=critical'),
            self::entry('TEST_MATCH', 'string', '', [], 'Filtro textual sobre archivo/caso.', "TEST_MATCH='mysql_migration_state_resolver'"),
            self::entry('TEST_LIST', 'bool', 'false', ['1', '0', 'true', 'false', 'yes', 'no', 'on', 'off'], 'Convierte la corrida en list-only.', 'TEST_LIST=1'),
            self::entry('TEST_FAIL_FAST', 'bool', 'true', ['1', '0', 'true', 'false', 'yes', 'no', 'on', 'off'], 'Corta lanzamiento de nuevos tests al primer fail.', 'TEST_FAIL_FAST=0'),
            self::entry('TEST_REQUIRE_TESTS', 'bool', 'false', ['1', '0', 'true', 'false', 'yes', 'no', 'on', 'off'], 'Hace fallar selección vacía.', 'TEST_REQUIRE_TESTS=1'),
            self::entry('TEST_JOBS', 'int', '1', [], 'Cantidad de workers intra-suite.', 'TEST_JOBS=4'),
            self::entry('TEST_DB_STRATEGY', 'string', 'shared', ['shared', 'per_worker', 'clean'], 'Estrategia declarada de DB para la suite.', 'TEST_DB_STRATEGY=per_worker'),
            self::entry('TEST_COVERAGE', 'bool', 'false', ['1', '0', 'true', 'false', 'yes', 'no', 'on', 'off'], 'Habilita coverage por test.', 'TEST_COVERAGE=1'),
            self::entry('TEST_COVERAGE_FORMAT', 'string', 'lcov', ['lcov', 'json', 'both'], 'Formato de coverage agregado.', 'TEST_COVERAGE_FORMAT=both'),
            self::entry('TEST_REPORT_KEEP', 'int', '5', [], 'Cantidad de reportes timestamped a conservar.', 'TEST_REPORT_KEEP=10'),
            self::entry('TEST_RUNS_INDEX_KEEP', 'int', '5', [], 'Cantidad de entradas a conservar en runs index.', 'TEST_RUNS_INDEX_KEEP=10'),
            self::entry('TEST_META_FAIL_FAST', 'bool', 'false', ['1', '0', 'true', 'false', 'yes', 'no', 'on', 'off'], 'Corta el target agregado al primer suite fail.', 'TEST_META_FAIL_FAST=1'),
            self::entry('TEST_CHILD_FAIL_FAST', 'bool', 'false', ['1', '0', 'true', 'false', 'yes', 'no', 'on', 'off'], 'Propaga fail-fast a suites hijas.', 'TEST_CHILD_FAIL_FAST=1'),
            self::entry('TEST_TARGET', 'string', 'all', ['all', 'back', 'front', 'back-php', 'back-python', 'front-php', 'front-js', 'migration-contract'], 'Target agregado por env cuando no se pasa argumento posicional.', 'TEST_TARGET=back-php'),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function doctorEntries(): array
    {
        return [
            self::entry('TESTKIT_DOCTOR_MODE', 'string', 'full', ['full', 'compact'], 'Modo por defecto del doctor.', 'TESTKIT_DOCTOR_MODE=compact'),
            self::entry('doctor --full', 'flag', 'off', ['--full', '--compact', '--dump', '--target=<target>'], 'Fuerza render completo del doctor.', './bin/testkit doctor --full migration-contract'),
            self::entry('doctor --compact', 'flag', 'off', ['--full', '--compact', '--dump', '--target=<target>'], 'Fuerza render compacto del doctor.', './bin/testkit doctor --compact'),
            self::entry('doctor --dump', 'flag', 'off', ['--full', '--compact', '--dump', '--target=<target>'], 'Expone checks serializados y config efectiva.', './bin/testkit doctor --dump --full'),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function wrapperEntries(): array
    {
        return [
            self::entry('TESTKIT_PROJECT_ROOT', 'path', '<repo del proyecto>', [], 'Root del repo bajo prueba.', 'export TESTKIT_PROJECT_ROOT=/path/to/project'),
            self::entry('TESTKIT_ROOT', 'path', '<repo testkit>', [], 'Root del repo testkit si no se usa autodetección.', 'export TESTKIT_ROOT=/path/to/testKit'),
            self::entry('TESTKIT_ENV_FILE', 'path', 'test/.env.test o .env.test', [], 'Override del env file del proyecto.', 'TESTKIT_ENV_FILE=/path/to/project/test/.env.test'),
            self::entry('TESTKIT_STACK', 'csv', 'mysql,redis', ['mysql', 'redis', 'pg', 'influx'], 'Selecciona compose overlays del stack.', 'TESTKIT_STACK=mysql,redis,pg'),
            self::entry('inspect config-schema', 'command', 'available', [], 'Lista variables soportadas, tipos, defaults y ejemplos.', './bin/testkit inspect config-schema'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function entry(
        string $key,
        string $type,
        string $default,
        array $allowed,
        string $purpose,
        string $example
    ): array {
        return [
            'key' => $key,
            'type' => $type,
            'default' => $default,
            'allowed' => $allowed,
            'purpose' => $purpose,
            'example' => $example,
        ];
    }
}
