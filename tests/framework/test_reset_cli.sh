#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "${repo_root}"

tmp_root="$(mktemp -d "${TMPDIR:-/tmp}/testkit_reset_cli.XXXXXX")"
cleanup() {
  rm -rf "${tmp_root}"
}
trap cleanup EXIT

host_root="${tmp_root}/host"
artifacts_root="${host_root}/.testkit"
mkdir -p \
  "${artifacts_root}/reports/runs/old_run" \
  "${artifacts_root}/mysql_profile/shards/old_run" \
  "${artifacts_root}/influx_profile/shards/old_run" \
  "${artifacts_root}/coverage/back_php" \
  "${artifacts_root}/locks/old_lock" \
  "${artifacts_root}/history" \
  "${artifacts_root}/baselines" \
  "${host_root}/test/coverage/front_php" \
  "${host_root}/test/back/example" \
  "${host_root}/test/seeds"

printf '{}\n' > "${artifacts_root}/reports/latest_run.json"
printf '{}\n' > "${artifacts_root}/reports/runs/old_run/result.json"
printf '{}\n' > "${artifacts_root}/mysql_profile/shards/old_run/profile.json"
printf '{}\n' > "${artifacts_root}/influx_profile/shards/old_run/profile.json"
printf '{}\n' > "${artifacts_root}/coverage/back_php/coverage.json"
printf '{}\n' > "${host_root}/test/coverage/front_php/coverage.json"
printf '{}\n' > "${artifacts_root}/history/history_20260817_120000.json"
printf '{}\n' > "${artifacts_root}/baselines/keep.manifest.json"
printf '<?php echo "keep";\n' > "${host_root}/test/back/example/example.test.php"
printf 'SELECT 1;\n' > "${host_root}/test/seeds/keep.sql"
printf 'TESTKIT_STACK=mysql\n' > "${host_root}/.env.test"

export TESTKIT_PROJECT_ROOT="${host_root}"
export TK_REPO_ROOT="${host_root}"
export TESTKIT_ARTIFACTS_ROOT="${artifacts_root}"
unset TEST_COVERAGE_ROOT || true
unset TEST_COVERAGE_DIR || true

set +e
php scripts/reset.php --json >/dev/null 2>&1
guard_status=$?
set -e
if [[ "${guard_status}" != "1" ]]; then
  echo "direct reset without stopped-container guard should exit 1, got ${guard_status}" >&2
  exit 1
fi

safe_json="$(TESTKIT_RESET_CONTAINERS_STOPPED=1 php scripts/reset.php --json)"
php -r '
$payload = json_decode(stream_get_contents(STDIN), true);
if (!is_array($payload)) { fwrite(STDERR, "safe reset output is not JSON\n"); exit(1); }
if (($payload["ok"] ?? null) !== true) { fwrite(STDERR, "safe reset should be ok\n"); exit(1); }
if (($payload["mode"] ?? null) !== "safe") { fwrite(STDERR, "safe reset mode mismatch\n"); exit(1); }
if (($payload["preserved"]["history"] ?? null) !== true) { fwrite(STDERR, "safe reset must preserve history\n"); exit(1); }
if (($payload["preserved"]["baselines"] ?? null) !== true) { fwrite(STDERR, "safe reset must preserve baselines\n"); exit(1); }
' <<< "${safe_json}"

for deleted_path in \
  "${artifacts_root}/reports" \
  "${artifacts_root}/mysql_profile/shards" \
  "${artifacts_root}/influx_profile/shards" \
  "${artifacts_root}/coverage" \
  "${artifacts_root}/locks" \
  "${host_root}/test/coverage"; do
  if [[ -e "${deleted_path}" ]]; then
    echo "safe reset should delete ${deleted_path}" >&2
    exit 1
  fi
done

for preserved_path in \
  "${artifacts_root}" \
  "${artifacts_root}/history/history_20260817_120000.json" \
  "${artifacts_root}/baselines/keep.manifest.json" \
  "${host_root}/.env.test" \
  "${host_root}/test/back/example/example.test.php" \
  "${host_root}/test/seeds/keep.sql"; do
  if [[ ! -e "${preserved_path}" ]]; then
    echo "safe reset deleted protected path ${preserved_path}" >&2
    exit 1
  fi
done

mkdir -p "${artifacts_root}/reports/runs/new_run" "${artifacts_root}/history"
printf '{}\n' > "${artifacts_root}/reports/latest_run.json"
printf '{}\n' > "${artifacts_root}/history/history_20260817_130000.json"

hard_json="$(TESTKIT_RESET_CONTAINERS_STOPPED=1 php scripts/reset.php --hard --json)"
php -r '
$payload = json_decode(stream_get_contents(STDIN), true);
if (!is_array($payload)) { fwrite(STDERR, "hard reset output is not JSON\n"); exit(1); }
if (($payload["ok"] ?? null) !== true) { fwrite(STDERR, "hard reset should be ok\n"); exit(1); }
if (($payload["mode"] ?? null) !== "hard") { fwrite(STDERR, "hard reset mode mismatch\n"); exit(1); }
if (($payload["preserved"]["history"] ?? null) !== false) { fwrite(STDERR, "hard reset must remove history\n"); exit(1); }
if (($payload["preserved"]["baselines"] ?? null) !== true) { fwrite(STDERR, "hard reset must preserve baselines\n"); exit(1); }
' <<< "${hard_json}"

if [[ -e "${artifacts_root}/reports" || -e "${artifacts_root}/history" ]]; then
  echo "hard reset should remove reports and history" >&2
  exit 1
fi
if [[ ! -f "${artifacts_root}/baselines/keep.manifest.json" ]]; then
  echo "hard reset deleted baseline manifest unexpectedly" >&2
  exit 1
fi

mock_bin="${tmp_root}/mock-bin"
mkdir -p "${mock_bin}"
cat > "${mock_bin}/docker" <<'MOCK'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "${DOCKER_LOG}"
exit 0
MOCK
chmod +x "${mock_bin}/docker"

export PATH="${mock_bin}:${PATH}"
export DOCKER_LOG="${tmp_root}/docker.log"
unset TESTKIT_ARTIFACTS_ROOT || true

: > "${DOCKER_LOG}"
./bin/testkit reset >/dev/null
mapfile -t safe_calls < "${DOCKER_LOG}"
if [[ "${#safe_calls[@]}" -ne 3 ]]; then
  echo "safe wrapper reset should make 3 docker calls, got ${#safe_calls[@]}" >&2
  exit 1
fi
if [[ "${safe_calls[0]}" != *" down --remove-orphans"* || "${safe_calls[0]}" == *" down -v "* ]]; then
  echo "safe wrapper reset must down without -v" >&2
  exit 1
fi
if [[ "${safe_calls[1]}" != *" run --rm --no-deps "* || "${safe_calls[1]}" != *"TESTKIT_RESET_CONTAINERS_STOPPED=1"* || "${safe_calls[1]}" != *"/workspace/testkit/scripts/reset.php"* ]]; then
  echo "safe wrapper reset artifact command mismatch" >&2
  exit 1
fi
if [[ "${safe_calls[2]}" != *" down --remove-orphans"* ]]; then
  echo "safe wrapper reset must finish with compose down" >&2
  exit 1
fi

: > "${DOCKER_LOG}"
./bin/testkit reset --hard >/dev/null
mapfile -t hard_calls < "${DOCKER_LOG}"
if [[ "${#hard_calls[@]}" -ne 3 ]]; then
  echo "hard wrapper reset should make 3 docker calls, got ${#hard_calls[@]}" >&2
  exit 1
fi
if [[ "${hard_calls[0]}" != *" down -v --remove-orphans"* ]]; then
  echo "hard wrapper reset must remove compose volumes" >&2
  exit 1
fi
if [[ "${hard_calls[1]}" != *"/workspace/testkit/scripts/reset.php --hard"* ]]; then
  echo "hard wrapper reset must forward --hard" >&2
  exit 1
fi

: > "${DOCKER_LOG}"
set +e
./bin/testkit reset --unknown >/dev/null 2>&1
unknown_status=$?
set -e
if [[ "${unknown_status}" != "2" ]]; then
  echo "unknown reset option should exit 2, got ${unknown_status}" >&2
  exit 1
fi
if [[ -s "${DOCKER_LOG}" ]]; then
  echo "unknown reset option must fail before Docker" >&2
  exit 1
fi

# PowerShell parity is part of the public wrapper contract even when this Linux
# smoke test cannot execute PowerShell.
grep -q 'Invoke-TestkitReset' bin/testkit.ps1
grep -q "'TESTKIT_RESET_CONTAINERS_STOPPED=1'" bin/testkit.ps1
grep -q "'/workspace/testkit/scripts/reset.php'" bin/testkit.ps1
grep -q "\$downCmd += '-v'" bin/testkit.ps1

echo 'Reset CLI contract PASS'
