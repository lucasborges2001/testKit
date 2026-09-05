#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
# shellcheck source=/dev/null
source "$ROOT/lib/bash/executor_capabilities.sh"

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

pass_json="$(testkit_executor_capabilities_probe bash realpath mktemp writable-tmp)"
grep -q '"schema":"testkit.executor-capabilities.v1"' <<<"$pass_json"
grep -q '"status":"PASS"' <<<"$pass_json"
grep -q '"bash":"PASS"' <<<"$pass_json"

mkdir -p "$TMP/bin"
for name in bash realpath mktemp rm uname; do
  target="$(command -v "$name")"
  ln -s "$target" "$TMP/bin/$name"
done
cat >"$TMP/bin/docker" <<'SH'
#!/usr/bin/env bash
if [[ "${1:-}" == "info" ]]; then exit 1; fi
if [[ "${1:-}" == "compose" && "${2:-}" == "version" ]]; then exit 0; fi
exit 0
SH
chmod +x "$TMP/bin/docker"

set +e
blocked_json="$(PATH="$TMP/bin" testkit_executor_capabilities_probe docker docker-daemon docker-compose)"
blocked_rc=$?
set -e
[[ "$blocked_rc" -eq 1 ]]
grep -q '"status":"BLOCKED"' <<<"$blocked_json"
grep -q '"missing":\["docker-daemon"\]' <<<"$blocked_json"

set +e
unsupported_json="$(testkit_executor_capabilities_probe network)"
unsupported_rc=$?
set -e
[[ "$unsupported_rc" -eq 2 ]]
grep -q '"status":"ERROR"' <<<"$unsupported_json"
grep -q '"code":"unsupported_requirement"' <<<"$unsupported_json"
grep -q '"requirement":"network"' <<<"$unsupported_json"

echo 'PASS executor_capabilities inventory=1 required_gate=1 docker_daemon=1 network_not_faked=1'
