#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/php/config/ContractRegistry.php';

use Testkit\Core\Config\ContractRegistry;

/** @param array<string,mixed> $payload */
function testkit_contract_print_json(array $payload): void
{
    echo json_encode(
        $payload,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    ) . PHP_EOL;
}

$args = array_values(array_slice($argv, 1));
$json = in_array('--json', $args, true);
$args = array_values(array_filter($args, static fn(string $arg): bool => $arg !== '--json'));
$command = strtolower(trim((string)($args[0] ?? 'show')));

try {
    switch ($command) {
        case 'show':
        case '':
            $payload = ContractRegistry::payload();
            if ($json) {
                testkit_contract_print_json($payload);
            } else {
                echo ContractRegistry::renderMarkdown();
            }
            exit(0);

        case 'validate':
            $errors = ContractRegistry::validate();
            $payload = [
                'ok' => $errors === [],
                'schema' => [
                    'name' => ContractRegistry::SCHEMA_NAME,
                    'version' => ContractRegistry::SCHEMA_VERSION,
                ],
                'digest' => ContractRegistry::digest(),
                'errors' => $errors,
            ];
            if ($json) {
                testkit_contract_print_json($payload);
            } else {
                echo $errors === []
                    ? "contract registry: PASS\n"
                    : "contract registry: FAIL\n- " . implode("\n- ", $errors) . "\n";
            }
            exit($errors === [] ? 0 : 2);

        case 'validate-selector':
            $kind = strtolower(trim((string)($args[1] ?? '')));
            $name = strtolower(trim((string)($args[2] ?? '')));
            $definition = ContractRegistry::definition($kind, $name);
            $payload = [
                'ok' => is_array($definition),
                'kind' => $kind,
                'name' => $name,
                'definition' => $definition,
            ];
            if ($json) {
                testkit_contract_print_json($payload);
            } elseif (is_array($definition)) {
                echo $kind . ':' . $name . PHP_EOL;
            }
            exit(is_array($definition) ? 0 : 2);

        case 'list-selectors':
            $kind = strtolower(trim((string)($args[1] ?? '')));
            $names = ContractRegistry::selectorNames($kind);
            if ($json) {
                testkit_contract_print_json(['kind' => $kind, 'selectors' => $names]);
            } else {
                echo implode(PHP_EOL, $names) . PHP_EOL;
            }
            exit(0);

        case 'render-doc':
            echo ContractRegistry::renderMarkdown();
            exit(0);

        case 'check-doc':
            $path = (string)($args[1] ?? dirname(__DIR__) . '/docs/CONTRACT_REGISTRY.md');
            $actual = is_file($path) ? file_get_contents($path) : false;
            $expected = ContractRegistry::renderMarkdown();
            $ok = is_string($actual) && $actual === $expected;
            if ($json) {
                testkit_contract_print_json([
                    'ok' => $ok,
                    'path' => $path,
                    'digest' => ContractRegistry::digest(),
                ]);
            } elseif (!$ok) {
                fwrite(STDERR, "contract registry doc drift: {$path}\n");
            }
            exit($ok ? 0 : 1);

        default:
            fwrite(STDERR, "contract: comando no soportado '{$command}'\n");
            exit(2);
    }
} catch (\InvalidArgumentException $e) {
    if ($json) {
        testkit_contract_print_json(['ok' => false, 'error' => $e->getMessage()]);
    } else {
        fwrite(STDERR, 'contract invalid request: ' . $e->getMessage() . PHP_EOL);
    }
    exit(2);
} catch (\Throwable $e) {
    if ($json) {
        testkit_contract_print_json(['ok' => false, 'error' => $e->getMessage()]);
    } else {
        fwrite(STDERR, 'contract error: ' . $e->getMessage() . PHP_EOL);
    }
    exit(3);
}
