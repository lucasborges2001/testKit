#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT
mkdir -p "$TMP_DIR/.testkit"

SECRET_VALUE='never-print-this-secret-7f91'
cat >"$TMP_DIR/.env" <<ENV
NORMAL=value
PAREN=alpha(beta)
EQUAL=a=b=c
QUOTED="hello world"
SECRET=$SECRET_VALUE
EMPTY=
export EXPORTED=yes
ENV

cat >"$TMP_DIR/contract.php" <<'PHP'
<?php
return [
    'source' => ['type' => 'file', 'path' => '.env'],
    'checks' => [
        ['key' => 'NORMAL', 'assert' => 'equals', 'value' => 'value'],
        ['key' => 'PAREN', 'assert' => 'equals', 'value' => 'alpha(beta)'],
        ['key' => 'EQUAL', 'assert' => 'equals', 'value' => 'a=b=c'],
        ['key' => 'QUOTED', 'assert' => 'equals', 'value' => 'hello world'],
        ['key' => 'SECRET', 'assert' => 'present', 'sensitive' => true],
        ['key' => 'EMPTY', 'assert' => 'equals', 'value' => ''],
        ['key' => 'EXPORTED', 'assert' => 'equals', 'value' => 'yes'],
        ['key' => 'MISSING', 'assert' => 'absent'],
    ],
];
PHP

TESTKIT_PROJECT_ROOT="$TMP_DIR" \
  bash "$ROOT/bin/testkit-env-contract" contract.php \
  --result-json .testkit/env-result.json \
  >"$TMP_DIR/stdout.txt" 2>"$TMP_DIR/stderr.txt"

php -r '
$j = json_decode((string)file_get_contents($argv[1]), true);
if (!is_array($j)) exit(1);
if (($j["schema"] ?? null) !== 1) exit(2);
if (($j["runner"] ?? null) !== "envContract") exit(3);
if (($j["status"] ?? null) !== "PASS") exit(4);
if (($j["exit_code"] ?? null) !== 0) exit(5);
if (($j["summary"]["checks"] ?? null) !== 8) exit(6);
if (($j["summary"]["failed"] ?? null) !== 0) exit(7);
' "$TMP_DIR/.testkit/env-result.json"

if grep -R -F "$SECRET_VALUE" \
    "$TMP_DIR/stdout.txt" \
    "$TMP_DIR/stderr.txt" \
    "$TMP_DIR/.testkit/env-result.json" >/dev/null; then
  echo "FAIL: env contract leaked a secret value" >&2
  exit 1
fi

cat >"$TMP_DIR/process-contract.php" <<'PHP'
<?php
return [
    'source' => ['type' => 'process'],
    'checks' => [
        ['key' => 'TK_PROCESS_PROBE', 'assert' => 'equals', 'value' => 'ready'],
        ['key' => 'TK_PROCESS_SECRET', 'assert' => 'present', 'sensitive' => true],
    ],
];
PHP
TK_PROCESS_PROBE=ready \
TK_PROCESS_SECRET="$SECRET_VALUE" \
TESTKIT_PROJECT_ROOT="$TMP_DIR" \
  bash "$ROOT/bin/testkit-env-contract" process-contract.php \
  >"$TMP_DIR/process.out" 2>"$TMP_DIR/process.err"
if grep -R -F "$SECRET_VALUE" "$TMP_DIR/process.out" "$TMP_DIR/process.err" >/dev/null; then
  echo "FAIL: process env contract leaked a secret value" >&2
  exit 1
fi

cat >"$TMP_DIR/bad-sensitive.php" <<'PHP'
<?php
return [
    'source' => ['type' => 'file', 'path' => '.env'],
    'checks' => [[
        'key' => 'SECRET',
        'assert' => 'equals',
        'value' => 'must-not-be-allowed',
        'sensitive' => true,
    ]],
];
PHP
set +e
TESTKIT_PROJECT_ROOT="$TMP_DIR" \
  bash "$ROOT/bin/testkit-env-contract" bad-sensitive.php >/dev/null 2>&1
rc=$?
set -e
[ "$rc" -eq 2 ] || { echo "FAIL: sensitive equals exit=$rc expected=2" >&2; exit 1; }

echo "OK TestKit env contract"
