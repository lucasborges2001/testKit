#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
mkdir -p "$TMP/config" "$TMP/scripts" "$TMP/.testkit"

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
        [
            'key' => 'native_build',
            'label' => 'native build',
            'working_directory' => '.',
            'commands' => [],
            'required' => true,
            'description' => 'host-native PowerShell build selected by an allowlisted suite',
            'risk' => 'disposable',
            'requires' => ['windows', 'codesys35'],
            'execution_backend' => 'host_native',
            'host_native' => [
                'kind' => 'powershell',
                'script' => 'scripts/native-build.ps1',
                'result_file' => '.testkit/native-build-result.json',
            ],
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
php -r '$j=json_decode(file_get_contents($argv[1]),true); $e=$j["evidence"]??null; $r=is_array($e)?($e["result"]??null):null; if(($j["status"]??null)!=="PASS"||($j["suite"]??null)!=="safe_owner"||($j["risk"]??null)!=="safe"||($j["execution_backend"]??null)!=="container"||($e["ok"]??null)!==true||($r["status"]??null)!=="PASS"||!is_array($r["summary"]??null)) exit(1);' "$TMP/pass.json"

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

write_request '{"schema":1,"enabled":true,"request_id":"native-r1","target":"ubuntudev","suite":"native_build"}'
set +e
TESTKIT_PROJECT_ROOT="$TMP" TESTKIT_REMOTE_TARGET=ubuntudev \
  php "$ROOT/runners/runRemoteHostAgent.php" config/suites.php config/request.json --json --admit-only > "$TMP/native-denied.json"
NATIVE_DENIED_RC=$?
set -e
[ "$NATIVE_DENIED_RC" -eq 2 ]
php -r '$j=json_decode(file_get_contents($argv[1]),true); if(($j["code"]??null)!=="risk_not_allowed") exit(1);' "$TMP/native-denied.json"

TESTKIT_PROJECT_ROOT="$TMP" TESTKIT_REMOTE_TARGET=ubuntudev \
  php "$ROOT/runners/runRemoteHostAgent.php" config/suites.php config/request.json --json --admit-only --allow-disposable > "$TMP/native-admitted.json"
php -r '$j=json_decode(file_get_contents($argv[1]),true); $h=$j["host_native"]??null; if(($j["status"]??null)!=="ADMITTED"||($j["execution_backend"]??null)!=="host_native"||($j["risk"]??null)!=="disposable"||!is_array($h)||($h["kind"]??null)!=="powershell"||($h["script"]??null)!=="scripts/native-build.ps1"||($h["result_file"]??null)!==".testkit/native-build-result.json") exit(1);' "$TMP/native-admitted.json"

set +e
TESTKIT_PROJECT_ROOT="$TMP" TESTKIT_REMOTE_TARGET=ubuntudev \
  php "$ROOT/runners/runRemoteHostAgent.php" config/suites.php config/request.json --json --allow-disposable > "$TMP/native-direct.json"
NATIVE_DIRECT_RC=$?
set -e
[ "$NATIVE_DIRECT_RC" -eq 2 ]
php -r '$j=json_decode(file_get_contents($argv[1]),true); if(($j["code"]??null)!=="host_native_requires_bridge") exit(1);' "$TMP/native-direct.json"

write_request '{"schema":1,"enabled":true,"request_id":"bad-r1","target":"ubuntudev","suite":"safe_owner","command":"rm -rf /"}'
set +e
TESTKIT_PROJECT_ROOT="$TMP" TESTKIT_REMOTE_TARGET=ubuntudev \
  bash "$ROOT/bin/testkit-remote-host-agent" config/suites.php config/request.json --json > "$TMP/bad.json"
BAD_RC=$?
set -e
[ "$BAD_RC" -eq 2 ]
php -r '$j=json_decode(file_get_contents($argv[1]),true); if(($j["code"]??null)!=="contract_error") exit(1);' "$TMP/bad.json"

echo "PASS remote_host_agent safe_default=1 host_envelope=normalized network_opt_in=1 host_native_admission=1 host_native_direct=blocked arbitrary_command=blocked"
