<?php
declare(strict_types=1);

$errors = [];

function tk_bootstrap_assert_true(bool $condition, string $message, array &$errors): void
{
    if (!$condition) {
        $errors[] = $message;
    }
}

$root = dirname(__DIR__, 2);
$bootstrap = $root . '/core/php/bootstrap.php';

try {
    require_once $bootstrap;
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: core/php/bootstrap.php threw ' . get_class($e) . ': ' . $e->getMessage() . "\n");
    fwrite(STDERR, 'Location: ' . $e->getFile() . ':' . $e->getLine() . "\n");
    exit(1);
}

$requiredClasses = [
    'Testkit\\Core\\DbProfiling\\MysqlProfileConfig' => 'core/php/dbprofiling/bootstrap.php',
    'Testkit\\Core\\DbProfiling\\MysqlProfileReporter' => 'core/php/dbprofiling/bootstrap.php',
    'Testkit\\Core\\DbProfiling\\QueryProfileCollector' => 'core/php/dbprofiling/bootstrap.php',
    'Testkit\\Core\\Suites\\BackPhpSuite' => 'core/php/bootstrap.php',
];

$influxBootstrap = $root . '/core/php/influxprofiling/bootstrap.php';
if (is_file($influxBootstrap)) {
    $requiredClasses += [
        'Testkit\\Core\\InfluxProfiling\\InfluxProfileConfig' => 'core/php/influxprofiling/bootstrap.php',
        'Testkit\\Core\\InfluxProfiling\\InfluxProfileReporter' => 'core/php/influxprofiling/bootstrap.php',
        'Testkit\\Core\\InfluxProfiling\\InfluxProfileCollector' => 'core/php/influxprofiling/bootstrap.php',
        'Testkit\\Core\\InfluxProfiling\\InfluxQueryFingerprint' => 'core/php/influxprofiling/bootstrap.php',
        'Testkit\\Core\\InfluxProfiling\\InfluxQueryAnalyzer' => 'core/php/influxprofiling/bootstrap.php',
    ];
}

foreach ($requiredClasses as $class => $expectedBootstrap) {
    tk_bootstrap_assert_true(
        class_exists($class),
        'Missing class ' . $class . ' after core/php/bootstrap.php. Expected bootstrap include: ' . $expectedBootstrap,
        $errors
    );
}

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, 'FAIL: ' . $error . "\n");
    }
    exit(1);
}

echo "Core bootstrap integrity PASS\n";
