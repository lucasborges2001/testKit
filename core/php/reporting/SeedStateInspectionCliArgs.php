<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting;

final class SeedStateInspectionCliArgs
{
    /**
     * @param array<int,string> $argv
     * @return array{command:string,options:array<string,mixed>,positionals:array<int,string>}
     */
    public static function parse(array $argv): array
    {
        $args = array_values(array_slice($argv, 1));
        $options = [
            'json' => false,
            'suite' => '',
            'run' => '',
        ];
        $positionals = [];

        foreach ($args as $arg) {
            if ($arg === '--json') {
                $options['json'] = true;
                continue;
            }

            if (str_starts_with($arg, '--suite=')) {
                $options['suite'] = substr($arg, strlen('--suite='));
                continue;
            }

            if (str_starts_with($arg, '--run=')) {
                $options['run'] = substr($arg, strlen('--run='));
                continue;
            }

            $positionals[] = $arg;
        }

        return [
            'command' => strtolower(trim((string)($args[0] ?? ''))),
            'options' => $options,
            'positionals' => $positionals,
        ];
    }

    public static function normalizeSuiteId(string $suiteId): string
    {
        $suiteId = strtolower(trim($suiteId));
        return str_replace('-', '_', $suiteId);
    }
}
