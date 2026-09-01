#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

cat >"$TMP_DIR/config.php" <<'PHP'
<?php
return [
    'output' => 'failures',
    'suites' => [[
        'key' => 'unit',
        'label' => 'entrypoint unit',
        'working_directory' => '.',
        'commands' => ["php -r 'exit(getenv(\"TESTKIT_PROJECT_ROOT\") ? 0 : 1);'"],
        'required' => true,
        'description' => 'Verifies the public host suite entrypoint.',
    ]],
];
PHP

TESTKIT_PROJECT_ROOT="$TMP_DIR" bash "$ROOT/bin/testkit-suite-config" config.php --list >"$TMP_DIR/list.txt"
grep -q 'unit' "$TMP_DIR/list.txt"

TESTKIT_PROJECT_ROOT="$TMP_DIR" bash "$ROOT/bin/testkit-suite-config" config.php unit --result-json result.json
php -r '
$j = json_decode((string)file_get_contents($argv[1]), true);
if (!is_array($j)) exit(1);
if (($j["schema"] ?? null) !== 1) exit(2);
if (($j["runner"] ?? null) !== "runSuiteConfig") exit(3);
if (($j["suite"] ?? null) !== "unit") exit(4);
if (($j["status"] ?? null) !== "PASS") exit(5);
if (($j["exit_code"] ?? null) !== 0) exit(6);
' "$TMP_DIR/result.json"

set +e
TESTKIT_PROJECT_ROOT="$TMP_DIR" bash "$ROOT/bin/testkit-suite-config" missing.php unit >/dev/null 2>&1
rc=$?
set -e
[ "$rc" -eq 2 ] || { echo "FAIL: missing config exit=$rc expected=2" >&2; exit 1; }

echo "OK TestKit public suite-config entrypoint"
