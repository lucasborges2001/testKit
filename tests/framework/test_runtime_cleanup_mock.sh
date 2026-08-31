#!/usr/bin/env bash
set -euo pipefail
repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
source "${repo_root}/lib/bash/runtime_cleanup.sh"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
log="$tmp/log"
cat > "$tmp/docker" <<'MOCK'
#!/usr/bin/env bash
set -euo pipefail
log="${FAKE_DOCKER_LOG}"
cmd="$1"; shift
case "$cmd" in
  ps)
    args=" $* "
    if [[ "$args" == *"-aq"* && "$args" == *"io.testkit.resource=database"* && "$args" != *"com.docker.compose.project="* ]]; then
      printf 'db-stale\ndb-fresh\ndb-active\n'
    elif [[ "$args" == *"-aq"* && "$args" == *"project=stale"* && "$args" == *"resource=database"* ]]; then printf 'db-stale\n'
    elif [[ "$args" == *"-aq"* && "$args" == *"project=fresh"* && "$args" == *"resource=database"* ]]; then printf 'db-fresh\n'
    elif [[ "$args" == *"-aq"* && "$args" == *"project=active"* && "$args" == *"resource=database"* ]]; then printf 'db-active\n'
    elif [[ "$args" == *"-q"* && "$args" == *"project=active"* && "$args" == *"oneoff=True"* ]]; then printf 'run-active\n'
    elif [[ "$args" == *"-aq"* && "$args" == *"project=stale"* ]]; then printf 'db-stale\nrunner-stale\n'
    elif [[ "$args" == *"-aq"* && "$args" == *"project=fresh"* ]]; then printf 'db-fresh\nrunner-fresh\n'
    elif [[ "$args" == *"-aq"* && "$args" == *"project=active"* ]]; then printf 'db-active\nrun-active\n'
    fi
    ;;
  inspect)
    id="${@: -1}"
    if [[ "$*" == *"com.docker.compose.project"* ]]; then
      case "$id" in db-stale) echo stale;; db-fresh) echo fresh;; db-active) echo active;; esac
    else
      case "$id" in
        db-stale) echo '2026-08-30T12:00:00Z';;
        db-fresh) echo '2026-08-30T20:00:00Z';;
        db-active) echo '2026-08-30T12:00:00Z';;
      esac
    fi
    ;;
  rm) echo "rm $*" >> "$log" ;;
  network)
    sub="$1"; shift
    if [[ "$sub" == ls ]]; then echo net-stale; else echo "network $sub $*" >> "$log"; fi
    ;;
  volume)
    sub="$1"; shift
    if [[ "$sub" == ls ]]; then echo vol-stale; else echo "volume $sub $*" >> "$log"; fi
    ;;
esac
MOCK
chmod +x "$tmp/docker"
export TESTKIT_DOCKER_BIN="$tmp/docker"
export FAKE_DOCKER_LOG="$log"
export TESTKIT_RUNTIME_CLEANUP_NOW_EPOCH="$(date -d '2026-08-30T21:00:00Z' +%s)"
export PROJECT_ROOT="$tmp/host"
mkdir -p "$PROJECT_ROOT"

dry="$(testkit_runtime_cleanup /tmp/env --older-than=4h --dry-run)"
grep -q 'CANDIDATE project=stale' <<< "$dry"
grep -q 'KEEP project=fresh' <<< "$dry"
grep -q 'KEEP project=active' <<< "$dry"
[[ ! -s "$log" ]]

apply="$(testkit_runtime_cleanup /tmp/env --older-than=4h --apply --force)"
grep -q 'DELETE project=stale' <<< "$apply"
grep -q 'KEEP project=fresh' <<< "$apply"
grep -q 'KEEP project=active' <<< "$apply"
grep -q 'rm -f db-stale runner-stale' "$log"
grep -q 'volume rm vol-stale' "$log"
[[ -f "$PROJECT_ROOT/.testkit/reports/cleanup/runtime_cleanup_latest.json" ]]

echo 'Runtime cleanup mock PASS'
