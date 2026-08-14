<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$bash = file_get_contents($root . '/bin/testkit') ?: '';
$pwsh = file_get_contents($root . '/bin/testkit.ps1') ?: '';
$errors = [];
foreach ([['bash', $bash], ['powershell', $pwsh]] as [$name, $content]) {
    if (!str_contains($content, 'COMPOSE_PROGRESS')) $errors[] = "{$name}: missing COMPOSE_PROGRESS";
    if (!str_contains($content, 'TESTKIT_CONSOLE_MODE')) $errors[] = "{$name}: missing TESTKIT_CONSOLE_MODE";
    if (!str_contains($content, 'quiet')) $errors[] = "{$name}: missing quiet compose progress";
    if (str_contains($content, "run -q") || str_contains($content, "'run','-q'")) $errors[] = "{$name}: must not silence test stdout with docker compose run -q";
}
if ($errors !== []) {
    foreach ($errors as $error) fwrite(STDERR, "FAIL: {$error}\n");
    exit(1);
}
echo "Wrapper compact output contract PASS\n";
