#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
mkdir -p "$TMP/config"

cat > "$TMP/config/suites.php" <<'PHP'
<?php
declare(strict_types=1);
return [
    'suite_policy_version' => 1,
    'output' => 'failures',
    'suites' => [
        [
            'key' => 'safe_owner',
            'label' => 'safe owner',
            'working_directory' => '.',
            'commands' => ['php -r "exit(0);"'],
            'required' => true,
            'description' => 'safe focal owner suite',
            'risk' => 'safe',
        ],
        [
            'key' => 'readonly_network',
            'label' => 'readonly network',
            'working_directory' => '.',
            'commands' => ['php -r "exit(0);"'],
            'required' => true,
            'description' => 'controlled read-only network contract',
            'risk' => 'safe',
            'requires' => ['network'],
        ],
    ],
];
PHP

write_request() {
  local body="$1"
  printf '%s\n' "$body" > "$TMP/config/request.json"
}

write_request '{"schema":1,"enabled":true,"request_id":"safe-r1","target":"ubuntudev","suite":"safe_owner"}'
TESTKIT_PROJECT_ROOT="$TMP" TESTKIT_REMOTE_TARGET=ubuntudev \
  bash "$ROOT/bin/testkit-remote-host-agent" config/suites.php config/request.json --json > "$TMP/pass.json"
php -r '$j=json_decode(file_get_contents($argv[1]),true); $e=$j["evidence"]??null; $r=is_array($e)?($e["result"]??null):null; if(($j["status"]??null)!=="PASS"||($j["suite"]??null)!=="safe_owner"||($j["risk"]??null)!=="safe"||($e["ok"]??null)!==true||($r["status"]??null)!=="PASS"||!is_array($r["summary"]??null)) exit(1);' "$TMP/pass.json"

write_request '{"schema":1,"enabled":true,"request_id":"net-r1","target":"ubuntudev","suite":"readonly_network"}'
set +e
TESTKIT_PROJECT_ROOT="$TMP" TESTKIT_REMOTE_TARGET=ubuntudev \
  bash "$ROOT/bin/testkit-remote-host-agent" config/suites.php config/request.json --json > "$TMP/denied.json"
DENIED_RC=$?
set -e
[ "$DENIED_RC" -eq 2 ]
php -r '$j=json_decode(file_get_contents($argv[1]),true); if(($j["code"]??null)!=="network_not_allowed") exit(1);' "$TMP/denied.json"

TESTKIT_PROJECT_ROOT="$TMP" TESTKIT_REMOTE_TARGET=ubuntudev \
  bash "$ROOT/bin/testkit-remote-host-agent" config/suites.php config/request.json --allow-network --json > "$TMP/net-pass.json"
php -r '$j=json_decode(file_get_contents($argv[1]),true); $e=$j["evidence"]??null; $r=is_array($e)?($e["result"]??null):null; if(($j["status"]??null)!=="PASS"||($j["risk"]??null)!=="safe"||!in_array("network",$j["requires"]??[],true)||($e["ok"]??null)!==true||($r["status"]??null)!=="PASS") exit(1);' "$TMP/net-pass.json"

write_request '{"schema":1,"enabled":true,"request_id":"bad-r1","target":"ubuntudev","suite":"safe_owner","command":"rm -rf /"}'
set +e
TESTKIT_PROJECT_ROOT="$TMP" TESTKIT_REMOTE_TARGET=ubuntudev \
  bash "$ROOT/bin/testkit-remote-host-agent" config/suites.php config/request.json --json > "$TMP/bad.json"
BAD_RC=$?
set -e
[ "$BAD_RC" -eq 2 ]
php -r '$j=json_decode(file_get_contents($argv[1]),true); if(($j["code"]??null)!=="contract_error") exit(1);' "$TMP/bad.json"

echo "PASS remote_host_agent safe_default=1 host_envelope=normalized network_opt_in=1 arbitrary_command=blocked"
