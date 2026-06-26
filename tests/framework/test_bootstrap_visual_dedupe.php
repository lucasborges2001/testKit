<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/php/bootstrap.php';

use Testkit\Core\Seeding\SeedConsoleNarrative;
use Testkit\Core\Seeding\SeedRuntimeContext;
use Testkit\Core\Store\StoreAdapter;

final class DummySeedStoreAdapter implements StoreAdapter
{
    public function driver(): string { return 'mysql'; }
    public function resolveDatabaseName(): string { return 'cargadores_test'; }
    public function connect(?string $database = null): PDO { throw new RuntimeException('not used'); }
    public function provision(?string $database = null): void {}
    public function reset(PDO $pdo): void {}
    public function clean(PDO $pdo): void {}
    public function databaseExists(string $database): bool { return true; }
    public function dropDatabase(string $database): void {}
    public function cloneDatabase(string $sourceDatabase, string $targetDatabase): void {}
    public function restoreSnapshot(string $artifactPath, ?string $database = null): void {}
}

$errors = [];

function assert_true(bool $condition, string $message, array &$errors): void
{
    if (!$condition) {
        $errors[] = $message;
    }
}

$context = new SeedRuntimeContext(
    'mysql',
    '/tmp/test/seeds/mysql',
    '/tmp/project',
    'layered',
    new DummySeedStoreAdapter(),
    'cargadores_test',
    null
);

ob_start();
SeedConsoleNarrative::beginSuiteBootstrap('back_php', 'mysql', 'shared', 'layered', 'cargadores_test');
SeedConsoleNarrative::printCompletion($context, 'Seed pipeline por capas aplicado correctamente');
$first = (string)ob_get_clean();

ob_start();
SeedConsoleNarrative::beginSuiteBootstrap('front_php', 'mysql', 'shared', 'layered', 'cargadores_test');
SeedConsoleNarrative::printCompletion($context, 'Seed pipeline por capas aplicado correctamente');
$second = (string)ob_get_clean();

assert_true(str_contains($first, 'Seed pipeline por capas aplicado correctamente'), 'first bootstrap should keep detailed completion line', $errors);
assert_true(!str_contains($first, '[testkit] bootstrap detail deduped'), 'first bootstrap should not emit deduped completion', $errors);

assert_true(str_contains($second, '[testkit] bootstrap detail deduped baseline_mode=layered resource=mysql/cargadores_test'), 'second bootstrap should emit compact deduped completion', $errors);
assert_true(!str_contains($second, 'Seed pipeline por capas aplicado correctamente'), 'second bootstrap should suppress detailed completion line', $errors);

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, 'FAIL: ' . $error . PHP_EOL);
    }
    exit(1);
}

echo "Bootstrap visual dedupe PASS\n";
