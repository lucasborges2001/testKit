<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/php/store/bootstrap.php';

use Testkit\Core\Store\StoreDriverContractException;
use Testkit\Core\Store\StoreRegistry;

$errors = [];
$keys = ['TEST_STORE_DRIVER', 'DB_DRIVER', 'TEST_DB_DRIVER', 'TEST_DB_DSN', 'PG_DB', 'TEST_PG_DB'];
$snapshot = [];
foreach ($keys as $key) {
    $snapshot[$key] = getenv($key);
}

$clear = static function () use ($keys): void {
    foreach ($keys as $key) {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }
};

$expectCode = static function (string $code, callable $call, string $label) use (&$errors): void {
    try {
        $call();
        $errors[] = $label . ': expected ' . $code . ', no exception thrown';
    } catch (StoreDriverContractException $e) {
        if ($e->contractCode() !== $code) {
            $errors[] = $label . ': expected ' . $code . ', got ' . $e->contractCode();
        }
    } catch (Throwable $e) {
        $errors[] = $label . ': unexpected ' . get_class($e) . ' ' . $e->getMessage();
    }
};

try {
    $clear();
    $expectCode('TEST_STORE_DRIVER_REQUIRED', static fn() => StoreRegistry::detectDriver(), 'missing driver');

    foreach ([
        'DB_DRIVER' => 'mysql',
        'TEST_DB_DRIVER' => 'pgsql',
        'TEST_DB_DSN' => 'mysql:host=example;dbname=test',
        'PG_DB' => 'app_test',
        'TEST_PG_DB' => 'app_test',
    ] as $legacyKey => $value) {
        $clear();
        putenv($legacyKey . '=' . $value);
        $expectCode(
            'TEST_STORE_DRIVER_REQUIRED',
            static fn() => StoreRegistry::detectDriver(),
            $legacyKey . ' must not select the driver'
        );
    }

    foreach (['mysql', 'pgsql', 'none'] as $driver) {
        $clear();
        putenv('TEST_STORE_DRIVER=' . $driver);
        $actual = StoreRegistry::detectDriver();
        if ($actual !== $driver) {
            $errors[] = "canonical {$driver}: expected {$driver}, got {$actual}";
        }
        if (StoreRegistry::normalizeDriver($driver) !== $driver) {
            $errors[] = "normalize canonical {$driver} changed the value";
        }
    }

    foreach (['pg', 'postgres', 'postgresql', 'MYSQL', 'PGSQL', ' mysql ', 'foo'] as $invalid) {
        $clear();
        putenv('TEST_STORE_DRIVER=' . $invalid);
        $expectCode(
            'TEST_STORE_DRIVER_INVALID',
            static fn() => StoreRegistry::detectDriver(),
            "invalid canonical '{$invalid}'"
        );
        $expectCode(
            'TEST_STORE_DRIVER_INVALID',
            static fn() => StoreRegistry::normalizeDriver($invalid),
            "invalid explicit '{$invalid}'"
        );
    }

    $root = dirname(__DIR__, 2);
    $forbiddenByFile = [
        'core/php/store/StoreRegistry.php' => ["getenv('DB_DRIVER')", "getenv('TEST_DB_DRIVER')", 'strtok(', "str_starts_with(\$driver, 'pg')"],
        'core/php/execution/ParallelGuard.php' => ["getenv('DB_DRIVER')", "getenv('TEST_DB_DRIVER')", 'strtok('],
        'core/php/seeding/SuiteSeedState.php' => ['safeDetectDriver', "return 'mysql';"],
        'core/php/config/ConfigSchema.php' => ["self::env('DB_DRIVER'"],
        'lib/bash/doctor/base_checks.sh' => ['${TEST_STORE_DRIVER:-mysql}', 'normalize_token "${TEST_STORE_DRIVER'],
        'lib/bash/doctor/capability_checks.sh' => ['DB_DRIVER', 'TEST_DB_DRIVER', 'TEST_DB_DSN'],
        'lib/powershell/Doctor.BaseChecks.ps1' => ["Normalize-TestkitDoctorToken \$env:TEST_STORE_DRIVER", "\$storeDriver = 'mysql'"],
        'lib/powershell/Doctor.CapabilityChecks.ps1' => ['DB_DRIVER', 'TEST_DB_DRIVER', 'TEST_DB_DSN'],
        'scripts/seed.sh' => ['TEST_DB_DRIVER', 'DB_DRIVER', 'has_service'],
        'scripts/seed.ps1' => ['TEST_DB_DRIVER', 'DB_DRIVER', 'serviceNames'],
        'scripts/db_clean.sh' => ['TEST_DB_DRIVER', 'DB_DRIVER'],
        'scripts/db_clean.ps1' => ['TEST_DB_DRIVER', 'DB_DRIVER'],
        '.github/workflows/ci.yml' => ['set_env_value DB_DRIVER'],
    ];
    foreach ($forbiddenByFile as $rel => $needles) {
        $source = (string)file_get_contents($root . '/' . $rel);
        foreach ($needles as $needle) {
            if (str_contains($source, $needle)) {
                $errors[] = "{$rel}: legacy driver selector remains: {$needle}";
            }
        }
    }

    $envExample = (string)file_get_contents($root . '/.env.test.example');
    if (!preg_match('/^TEST_STORE_DRIVER=mysql$/m', $envExample)) {
        $errors[] = '.env.test.example must declare TEST_STORE_DRIVER=mysql';
    }
    if (!preg_match('/^TEST_STORE_PROVISION=managed$/m', $envExample)) {
        $errors[] = '.env.test.example must declare TEST_STORE_PROVISION=managed';
    }
    if (!preg_match('/^TEST_MYSQL_ADMIN_USER=root$/m', $envExample)) {
        $errors[] = '.env.test.example managed mysql path must declare TEST_MYSQL_ADMIN_USER=root';
    }
    if (preg_match('/^DB_DRIVER=/m', $envExample)) {
        $errors[] = '.env.test.example must not declare DB_DRIVER as driver selector';
    }

    $docsEnvExample = (string)file_get_contents($root . '/docs/examples/.env.test.example');
    if (!preg_match('/^TEST_STORE_DRIVER=mysql$/m', $docsEnvExample)) {
        $errors[] = 'docs/examples/.env.test.example must declare TEST_STORE_DRIVER=mysql';
    }
    if (!preg_match('/^TEST_STORE_PROVISION=managed$/m', $docsEnvExample)) {
        $errors[] = 'docs/examples/.env.test.example must declare TEST_STORE_PROVISION=managed';
    }
    if (!preg_match('/^TEST_MYSQL_ADMIN_USER=root$/m', $docsEnvExample)) {
        $errors[] = 'docs/examples/.env.test.example managed mysql path must declare TEST_MYSQL_ADMIN_USER=root';
    }
} finally {
    foreach ($snapshot as $key => $value) {
        if ($value === false) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        } else {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}

if ($errors !== []) {
    fwrite(STDERR, "Store driver explicit contract failed:\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}

echo "Store driver explicit contract PASS\n";
