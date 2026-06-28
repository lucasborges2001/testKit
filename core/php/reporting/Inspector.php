<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting;

require_once __DIR__ . '/InspectionPayloadBuilder.php';
require_once __DIR__ . '/InspectionTextPrinter.php';

final class Inspector
{
    /**
     * @param array<int,string> $argv
     */
    public static function runCli(array $argv): int
    {
        [$command, $positionals, $options] = self::parseArgs($argv);

        if ($command === '' || in_array($command, ['help', '--help', '-h'], true)) {
            InspectionTextPrinter::printHelp();
            return 0;
        }

        try {
            $payload = match ($command) {
                'latest' => InspectionPayloadBuilder::latest((string)($options['run'] ?? '')),
                'run' => InspectionPayloadBuilder::run((string)($positionals[0] ?? '')),
                'failure' => InspectionPayloadBuilder::failure((string)($options['run'] ?? ''), (bool)($options['latest'] ?? false)),
                'seed-state' => InspectionPayloadBuilder::seedState((string)($options['run'] ?? ''), self::normalizeSuiteId((string)($options['suite'] ?? ''))),
                'concurrency' => InspectionPayloadBuilder::concurrency((string)($options['run'] ?? '')),
                default => null,
            };
        } catch (\Throwable $e) {
            if ((bool)($options['json'] ?? false)) {
                self::printJson(['ok' => false, 'error' => $e->getMessage()]);
            } else {
                fwrite(STDERR, 'inspect error: ' . $e->getMessage() . PHP_EOL);
            }
            return 2;
        }

        if (!is_array($payload)) {
            if ((bool)($options['json'] ?? false)) {
                self::printJson(['ok' => false, 'error' => 'unknown inspect command']);
            } else {
                fwrite(STDERR, "inspect error: unknown command '{$command}'" . PHP_EOL);
                InspectionTextPrinter::printHelp();
            }
            return 2;
        }

        if ((bool)($options['json'] ?? false)) {
            self::printJson($payload);
            return 0;
        }

        InspectionTextPrinter::print($command, $payload);
        return 0;
    }

    /**
     * @param array<int,string> $argv
     * @return array{0:string,1:array<int,string>,2:array<string,mixed>}
     */
    private static function parseArgs(array $argv): array
    {
        $args = array_values(array_slice($argv, 1));
        $options = ['json' => false, 'latest' => false, 'suite' => '', 'run' => ''];
        $positionals = [];

        foreach ($args as $arg) {
            if ($arg === '--json') {
                $options['json'] = true;
                continue;
            }
            if ($arg === '--latest') {
                $options['latest'] = true;
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

        $command = strtolower(trim((string)($positionals[0] ?? '')));
        $positionals = array_values(array_slice($positionals, $command !== '' ? 1 : 0));
        return [$command, $positionals, $options];
    }

    private static function normalizeSuiteId(string $suiteId): string
    {
        return str_replace('-', '_', strtolower(trim($suiteId)));
    }

    /** @param array<string,mixed> $payload */
    private static function printJson(array $payload): void
    {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || $json === '') {
            throw new \RuntimeException('no se pudo serializar la salida JSON de inspect');
        }
        echo $json . PHP_EOL;
    }
}
