#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
mkdir -p "$TMP/config"

write_request() {
  printf '%s\n' "$1" > "$TMP/config/request.json"
}
run_route() {
  TESTKIT_PROJECT_ROOT="$TMP" TESTKIT_REMOTE_PLATFORM="${1:-linux}" TESTKIT_REMOTE_TARGET="${2:-executor-a}" \
    php "$ROOT/runners/runRemoteRequestRoute.php" config/request.json --json
}

write_request '{"schema":3,"enabled":true,"request_id":"linux-r1","platform":"linux","profile":"suite","suite":"safe_owner"}'
run_route linux executor-a > "$TMP/linux.json"
php -r '$j=json_decode(file_get_contents($argv[1]),true); if(($j["status"]??null)!=="ELIGIBLE"||($j["mode"]??null)!=="platform"||($j["selector"]["platform"]??null)!=="linux") exit(1);' "$TMP/linux.json"

write_request '{"schema":3,"enabled":true,"request_id":"any-r1","platform":"any","profile":"suite","suite":"safe_owner"}'
run_route windows executor-b > "$TMP/any.json"
php -r '$j=json_decode(file_get_contents($argv[1]),true); if(($j["status"]??null)!=="ELIGIBLE"||($j["local"]["platform"]??null)!=="windows") exit(1);' "$TMP/any.json"

write_request '{"schema":3,"enabled":true,"request_id":"windows-r1","platform":"windows","profile":"suite","suite":"safe_owner"}'
run_route linux executor-a > "$TMP/mismatch.json"
php -r '$j=json_decode(file_get_contents($argv[1]),true); if(($j["status"]??null)!=="PLATFORM_MISMATCH") exit(1);' "$TMP/mismatch.json"

write_request '{"schema":2,"enabled":true,"request_id":"legacy-r1","target":"executor-a","profile":"suite","suite":"safe_owner"}'
run_route linux executor-a > "$TMP/legacy.json"
php -r '$j=json_decode(file_get_contents($argv[1]),true); if(($j["status"]??null)!=="ELIGIBLE"||($j["mode"]??null)!=="target") exit(1);' "$TMP/legacy.json"

write_request '{"schema":3,"enabled":false,"request_id":"disabled-r1","platform":"linux","profile":"suite","suite":"safe_owner"}'
run_route linux executor-a > "$TMP/disabled.json"
php -r '$j=json_decode(file_get_contents($argv[1]),true); if(($j["status"]??null)!=="DISABLED") exit(1);' "$TMP/disabled.json"

write_request '{"schema":3,"enabled":true,"request_id":"bad-r1","target":"executor-a","platform":"linux","profile":"suite","suite":"safe_owner"}'
set +e
run_route linux executor-a > "$TMP/bad.json"
BAD_RC=$?
set -e
[ "$BAD_RC" -eq 2 ]
php -r '$j=json_decode(file_get_contents($argv[1]),true); if(($j["code"]??null)!=="contract_error") exit(1);' "$TMP/bad.json"

write_request '{"schema":3,"enabled":true,"request_id":"bad-platform-r1","platform":"solaris","profile":"suite","suite":"safe_owner"}'
set +e
run_route linux executor-a > "$TMP/bad-platform.json"
BAD_PLATFORM_RC=$?
set -e
[ "$BAD_PLATFORM_RC" -eq 2 ]

printf 'PASS remote_request_route schema3_platform=1 any=1 mismatch=1 legacy_target=1 disabled=1 target_forbidden=1\n'
