<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/core/php/reporting/ConsoleMode.php';

use Testkit\Core\Reporting\ConsoleMode;

function fail_console_mode(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

putenv('TESTKIT_CONSOLE_MODE');
if (!ConsoleMode::isCompact() || ConsoleMode::current() !== 'compact') {
    fail_console_mode('compact must be the default human console mode');
}

putenv('TESTKIT_CONSOLE_MODE=live');
if (ConsoleMode::isCompact() || ConsoleMode::current() !== 'live') {
    fail_console_mode('live override must disable compact mode');
}

putenv('TESTKIT_CONSOLE_MODE=unexpected');
if (!ConsoleMode::isCompact()) {
    fail_console_mode('unknown values must fail safe to compact presentation');
}

putenv('TESTKIT_CONSOLE_MODE');
echo "Console mode contract PASS\n";
