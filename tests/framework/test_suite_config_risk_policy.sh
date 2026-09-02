#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

cat >"$TMP_DIR/config.php" <<'PHP'
<?php
return [
    'output' => 'failures',
    'suite_policy_version' => 1,
    'suites' => [
        [
            'key' => 'safe',
            'label' => 'safe suite',
            'working_directory' => '.',
            'commands' => ["php -r 'file_put_contents(\"safe.marker\", \"ok\");'"],
            'required' => true,
            'description' => 'Safe policy fixture.',
            'risk' => 'safe',
            'requires' => ['php'],
            'exclusive' => false,
        ],
        [
            'key' => 'persistent',
            'label' => 'persistent suite',
            'working_directory' => '.',
            'commands' => ["php -r 'file_put_contents(\"persistent.marker\", \"ok\");'"],
            'required' => true,
            'description' => 'Persistent policy fixture.',
            'risk' => 'persistent',
            'requires' => ['php'],
            'exclusive' => true,
            'cleanup' => [
                'strategy' => 'self',
                'guaranteed' => true,
                'description' => 'Fixture owns cleanup in finally/trap.',
            ],
        ],
        [
            'key' => 'aggregate',
            'label' => 'aggregate suite',
            'working_directory' => '.',
            'suites' => ['safe', 'persistent'],
            'required' => true,
            'description' => 'Composite policy fixture.',
            'risk' => 'persistent',
            'exclusive' => true,
        ],
    ],
];
PHP

TESTKIT_PROJECT_ROOT="$TMP_DIR" bash "$ROOT/bin/testkit-suite-config" config.php safe >/dev/null
[ -f "$TMP_DIR/safe.marker" ]

rm -f "$TMP_DIR/persistent.marker"
set +e
TESTKIT_PROJECT_ROOT="$TMP_DIR" bash "$ROOT/bin/testkit-suite-config" config.php persistent \
  >"$TMP_DIR/persistent-denied.out" 2>"$TMP_DIR/persistent-denied.err"
rc=$?
set -e
[ "$rc" -eq 2 ] || { echo "FAIL: persistent without opt-in exit=$rc expected=2" >&2; exit 1; }
[ ! -f "$TMP_DIR/persistent.marker" ] || { echo "FAIL: denied persistent suite executed" >&2; exit 1; }
grep -q -- '--allow-persistent' "$TMP_DIR/persistent-denied.err"

TESTKIT_PROJECT_ROOT="$TMP_DIR" bash "$ROOT/bin/testkit-suite-config" config.php persistent --allow-persistent >/dev/null
[ -f "$TMP_DIR/persistent.marker" ]

rm -f "$TMP_DIR/persistent.marker"
set +e
TESTKIT_PROJECT_ROOT="$TMP_DIR" bash "$ROOT/bin/testkit-suite-config" config.php aggregate >/dev/null 2>&1
rc=$?
set -e
[ "$rc" -eq 2 ] || { echo "FAIL: composite persistent without opt-in exit=$rc expected=2" >&2; exit 1; }
[ ! -f "$TMP_DIR/persistent.marker" ] || { echo "FAIL: denied composite executed persistent child" >&2; exit 1; }

TESTKIT_PROJECT_ROOT="$TMP_DIR" bash "$ROOT/bin/testkit-suite-config" config.php aggregate --allow-persistent >/dev/null
[ -f "$TMP_DIR/persistent.marker" ]

cat >"$TMP_DIR/bad-cleanup.php" <<'PHP'
<?php
return [
    'suite_policy_version' => 1,
    'suites' => [[
        'key' => 'persistent',
        'label' => 'bad cleanup',
        'working_directory' => '.',
        'commands' => ['true'],
        'required' => true,
        'description' => 'Must be rejected.',
        'risk' => 'persistent',
    ]],
];
PHP
set +e
TESTKIT_PROJECT_ROOT="$TMP_DIR" bash "$ROOT/bin/testkit-suite-config" bad-cleanup.php --list >/dev/null 2>&1
rc=$?
set -e
[ "$rc" -eq 2 ] || { echo "FAIL: persistent missing cleanup exit=$rc expected=2" >&2; exit 1; }

cat >"$TMP_DIR/legacy.php" <<'PHP'
<?php
return [
    'output' => 'failures',
    'suites' => [[
        'key' => 'legacy',
        'label' => 'legacy catalog',
        'working_directory' => '.',
        'commands' => ['true'],
        'required' => true,
        'description' => 'Legacy catalogs stay compatible.',
    ]],
];
PHP
TESTKIT_PROJECT_ROOT="$TMP_DIR" bash "$ROOT/bin/testkit-suite-config" legacy.php legacy >/dev/null

echo "OK TestKit suite risk policy"
