<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/php/bootstrap.php';
require_once __DIR__ . '/../core/php/config/ConfigSchema.php';
require_once __DIR__ . '/../core/php/reporting/SeedStateInspector.php';

use Testkit\Core\Config\ConfigSchema;
use Testkit\Core\Config\ContractRegistry;

if (($argv[1] ?? '') === 'config-schema') {
    $payload = ContractRegistry::configSchemaPayload(ConfigSchema::inspectPayload());
    if (in_array('--json', $argv, true)) {
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded) || $encoded === '') {
            fwrite(STDERR, "inspect error: no se pudo serializar config-schema\n");
            exit(2);
        }
        echo $encoded . PHP_EOL;
        exit(0);
    }

    echo ContractRegistry::renderConfigSchemaText($payload);
    exit(0);
}

$seedStateExit = \Testkit\Core\Reporting\SeedStateInspector::maybeHandleCli($argv);
if (is_int($seedStateExit)) {
    exit($seedStateExit);
}

exit(\Testkit\Core\Reporting\Inspector::runCli($argv));
