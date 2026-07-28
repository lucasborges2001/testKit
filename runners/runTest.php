<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/php/bootstrap.php';
require_once __DIR__ . '/../core/php/config/RunRequest.php';

use Testkit\Core\Config\ContractRegistry;
use Testkit\Core\Config\RunRequest;
use Testkit\Core\Suites\MetaRunner;

\Testkit\Core\Common\AgentMode::applyRuntimeEnv();

try {
    $request = RunRequest::parse($argv);
    if ($request->help) {
        echo ContractRegistry::renderRunHelp();
        exit(0);
    }

    RunRequest::assertNoLegacyTargetEnvironment();
    $request->applyEnvironment();
    exit(MetaRunner::run($request->selectorName));
} catch (\InvalidArgumentException $e) {
    fwrite(
        STDERR,
        'runTest.php: ' . $e->getMessage() . PHP_EOL
        . 'Usá php runTest.php --help para ver la superficie soportada.' . PHP_EOL
    );
    exit(2);
}
