<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$runner = $root . '/scripts/static_checks.php';
if (!is_file($runner)) {
    fwrite(STDERR, "FAIL: missing scripts/static_checks.php\n");
    exit(1);
}

$tmp = sys_get_temp_dir() . '/testkit-static-' . bin2hex(random_bytes(5));
mkdir($tmp, 0777, true);
try {
    file_put_contents($tmp . '/good.php', "<?php\ndeclare(strict_types=1);\necho 'ok';\n");
    $cmd = 'NO_COLOR=1 TESTKIT_STATIC_ROOT=' . escapeshellarg($tmp)
        . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' php 2>&1';
    exec($cmd, $okOutput, $okCode);
    $ok = implode("\n", $okOutput);
    if ($okCode !== 0 || !str_contains($ok, 'PASS PHP lint') || !str_contains($ok, '1/1')) {
        fwrite(STDERR, "FAIL: passing PHP lint contract\n{$ok}\n");
        exit(1);
    }

    file_put_contents($tmp . '/bad.php', "<?php\nif (\n");
    exec($cmd, $badOutput, $badCode);
    $bad = implode("\n", $badOutput);
    if ($badCode === 0 || !str_contains($bad, 'FAIL PHP lint') || !str_contains($bad, 'bad.php') || !str_contains($bad, 'rerun: php -l bad.php')) {
        fwrite(STDERR, "FAIL: failing PHP lint contract\n{$bad}\n");
        exit(1);
    }
} finally {
    foreach (glob($tmp . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($tmp);
}

echo "Static checks contract PASS\n";
