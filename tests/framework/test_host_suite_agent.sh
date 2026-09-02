#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

PROJECT="$TMP/project"
mkdir -p "$PROJECT"

cat > "$PROJECT/suites.php" <<'PHP'
<?php
declare(strict_types=1);
return [
    'output' => 'failures',
    'suite_policy_version' => 1,
    'suites' => [
        [
            'key' => 'pass_host',
            'label' => 'pass host',
            'description' => 'Passing host-agent fixture.',
            'working_directory' => '.',
            'required' => true,
            'risk' => 'safe',
            'requires' => ['php'],
            'exclusive' => false,
            'commands' => ['php -r "exit(0);"'],
        ],
        [
            'key' => 'fail_host',
            'label' => 'fail host',
            'description' => 'Failing host-agent fixture.',
            'working_directory' => '.',
            'required' => true,
            'risk' => 'safe',
            'requires' => ['php'],
            'exclusive' => false,
            'commands' => ['php -r "exit(7);"'],
        ],
    ],
];
PHP

export TESTKIT_PROJECT_ROOT="$PROJECT"
export TK_REPO_ROOT="$PROJECT"

PASS_JSON="$TMP/pass.json"
bash "$ROOT/bin/testkit-host-agent" suites.php pass_host --goal=host-pass-contract --json > "$PASS_JSON"

php -r '
$j=json_decode((string)file_get_contents($argv[1]),true);
if(!is_array($j)) exit(10);
if(($j["ok"]??null)!==true) exit(11);
if(($j["mode"]??null)!=="host_suite_agent") exit(12);
if(($j["suite"]??null)!=="pass_host") exit(13);
if(($j["decision"]["outcome_status"]??null)!=="passed") exit(14);
if(($j["decision"]["agent_mode"]["mode"]??null)!=="agent") exit(15);
if(($j["artifact"]["recorded"]??null)!==true) exit(16);
$run=(string)($j["run_id"]??"");
if($run==="") exit(17);
$latest=$argv[2]."/.testkit/reports/latest_run.json";
if(!is_file($latest)) exit(18);
' "$PASS_JSON" "$PROJECT"

set +e
bash "$ROOT/bin/testkit-host-agent" suites.php fail_host --goal=host-fail-contract --json > "$TMP/fail.json"
FAIL_RC=$?
set -e
[ "$FAIL_RC" -eq 7 ] || {
    echo "FAIL: expected host-agent effective exit 7, got $FAIL_RC" >&2
    exit 20
}

RUN_ID="$(php -r '$j=json_decode((string)file_get_contents($argv[1]),true); echo (string)($j["run_id"]??"");' "$TMP/fail.json")"
[ -n "$RUN_ID" ] || exit 21

php -r '
$j=json_decode((string)file_get_contents($argv[1]),true);
if(!is_array($j)) exit(30);
if(($j["ok"]??null)!==false) exit(31);
if(($j["decision"]["outcome_status"]??null)!=="failed") exit(32);
if(($j["decision"]["evidence_valid"]??null)!==true) exit(33);
if(($j["decision"]["first_actionable_failure"]["kind"]??null)!=="host_suite_command_failure") exit(34);
if(($j["execution"]["exit_code"]??null)!==7) exit(35);
if(($j["execution"]["runner_exit_code"]??null)!==1) exit(36);
if(($j["result"]["exit_code"]??null)!==1) exit(37);
$commands=(array)($j["result"]["commands"]??[]);
if(($commands[0]["exit_code"]??null)!==7) exit(38);
' "$TMP/fail.json"

TESTKIT_MODE=agent php "$ROOT/scripts/agent-run.php" --run="$RUN_ID" --goal=host-agent-followup --json > "$TMP/decision.json"
php -r '
$j=json_decode((string)file_get_contents($argv[1]),true);
if(!is_array($j)) exit(40);
if(($j["outcome_status"]??null)!=="failed") exit(41);
if(($j["evidence_valid"]??null)!==true) exit(42);
if(($j["agent_mode"]["mode"]??null)!=="agent") exit(43);
if(($j["first_actionable_failure"]["kind"]??null)!=="host_suite_command_failure") exit(44);
' "$TMP/decision.json"

echo "Host suite agent bridge PASS"
