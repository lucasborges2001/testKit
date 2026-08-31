#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
# shellcheck source=/dev/null
source "${repo_root}/lib/bash/runtime_cleanup.sh"

assert_eq() {
  local expected="$1"
  local actual="$2"
  local message="$3"
  if [[ "${expected}" != "${actual}" ]]; then
    echo "FAIL ${message}: expected=${expected} actual=${actual}" >&2
    exit 1
  fi
}

assert_eq 14400 "$(testkit_runtime_age_to_seconds 4h)" '4h parsing'
assert_eq 900 "$(testkit_runtime_age_to_seconds 15m)" '15m parsing'
assert_eq $'keep\tACTIVE_RUN' "$(testkit_runtime_decision 20000 1 14400)" 'active run protection'
assert_eq $'keep\tTTL_NOT_EXPIRED' "$(testkit_runtime_decision 14399 0 14400)" 'ttl protection'
assert_eq $'delete\tRUNTIME_TTL_EXPIRED' "$(testkit_runtime_decision 14400 0 14400)" 'ttl expiry'

if testkit_runtime_age_to_seconds '4hours' >/dev/null 2>&1; then
  echo 'FAIL invalid TTL accepted' >&2
  exit 1
fi

set +e
testkit_runtime_cleanup /tmp/unused --apply >/dev/null 2>&1
rc=$?
set -e
assert_eq 2 "${rc}" '--apply must require --force'

for file in compose.mysql.yaml compose.pg.yaml compose.influx.yaml; do
  grep -q 'io.testkit.runtime: "true"' "${repo_root}/${file}"
  grep -q 'io.testkit.resource: "database"' "${repo_root}/${file}"
  grep -q 'io.testkit.resource: "database-volume"' "${repo_root}/${file}"
done

grep -q "== 'runtime'" "${repo_root}/bin/testkit"
grep -q "RuntimeCleanup.ps1" "${repo_root}/bin/testkit.ps1"
grep -q "TESTKIT_RUNTIME_AUTO_CLEANUP" "${repo_root}/lib/bash/runtime_cleanup.sh"
grep -q "TESTKIT_RUNTIME_AUTO_CLEANUP" "${repo_root}/lib/powershell/RuntimeCleanup.ps1"

echo 'Runtime cleanup contract PASS'
