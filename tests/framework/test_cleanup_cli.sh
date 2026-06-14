#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "${repo_root}"

tmp_root="$(mktemp -d "${TMPDIR:-/tmp}/testkit_cleanup_cli.XXXXXX")"
cleanup() {
  rm -rf "${tmp_root}"
}
trap cleanup EXIT

host_root="${tmp_root}/host"
artifacts_root="${host_root}/.testkit"
runs_root="${artifacts_root}/reports/runs"
mkdir -p "${runs_root}"

create_run() {
  local name="$1"
  local touch_stamp="$2"
  local dir="${runs_root}/${name}"

  mkdir -p "${dir}"
  printf '{}\n' > "${dir}/meta_latest.json"
  printf '{}\n' > "${dir}/meta_20260614_120000.json"

  # Cleanup retention sorts run directories by filesystem mtime, not by name.
  # Set directory mtimes explicitly so this smoke test is deterministic even
  # on filesystems where several mkdir/write operations land in the same second.
  touch -t "${touch_stamp}" "${dir}"
}

# Oldest first, newest last, with explicit mtimes.
create_run '20260614T115700Z_fourth' '202606141157.00'
create_run '20260614T115800Z_third'  '202606141158.00'
create_run '20260614T115900Z_second' '202606141159.00'
create_run '20260614T120000Z_newest' '202606141200.00'

printf '{}\n' > "${artifacts_root}/reports/meta_latest.json"
mkdir -p "${artifacts_root}/coverage/back_php"
printf '{"ok":true}\n' > "${artifacts_root}/coverage/back_php/coverage.json"

export TESTKIT_PROJECT_ROOT="${host_root}"
export TK_REPO_ROOT="${host_root}"
export TESTKIT_ARTIFACTS_ROOT="${artifacts_root}"
unset TEST_COVERAGE_ROOT || true
unset TEST_COVERAGE_DIR || true

dry_coverage_json="$(php scripts/cleanup.php coverage --dry-run --json)"
php -r '
$payload = json_decode(stream_get_contents(STDIN), true);
if (!is_array($payload)) { fwrite(STDERR, "coverage dry-run output is not JSON\n"); exit(1); }
if (($payload["groups"]["coverage"]["paths_scanned"] ?? null) !== 1) { fwrite(STDERR, "coverage dry-run scan count mismatch\n"); exit(1); }
$found = false;
foreach (($payload["candidates"] ?? []) as $candidate) {
    if (is_array($candidate) && ($candidate["path"] ?? null) === ".testkit/coverage/back_php") { $found = true; }
}
if (!$found) { fwrite(STDERR, "coverage dry-run did not include .testkit/coverage/back_php\n"); exit(1); }
' <<< "${dry_coverage_json}"

if [[ ! -d "${artifacts_root}/coverage/back_php" ]]; then
  echo "coverage dry-run deleted unexpectedly" >&2
  exit 1
fi

TEST_COVERAGE_DIR='.testkit/coverage/back_php' php scripts/cleanup.php coverage --dry-run --json \
  | php -r '
$payload = json_decode(stream_get_contents(STDIN), true);
if (!is_array($payload)) { fwrite(STDERR, "TEST_COVERAGE_DIR output is not JSON\n"); exit(1); }
$found = false;
foreach (($payload["candidates"] ?? []) as $candidate) {
    if (is_array($candidate) && ($candidate["path"] ?? null) === ".testkit/coverage/back_php") { $found = true; }
}
if (!$found) { fwrite(STDERR, "TEST_COVERAGE_DIR was not resolved by cleanup runtime\n"); exit(1); }
'

set +e
unsafe_json="$(TEST_COVERAGE_DIR='.testkit' php scripts/cleanup.php coverage --dry-run --json)"
unsafe_status=$?
set -e
if [[ "${unsafe_status}" != "1" ]]; then
  echo "unsafe TEST_COVERAGE_DIR should exit 1, got ${unsafe_status}" >&2
  exit 1
fi
php -r '
$payload = json_decode(stream_get_contents(STDIN), true);
if (!is_array($payload)) { fwrite(STDERR, "unsafe coverage output is not JSON\n"); exit(1); }
if (($payload["groups"]["coverage"]["skipped_unsafe"] ?? null) !== 1) { fwrite(STDERR, "unsafe coverage was not rejected\n"); exit(1); }
' <<< "${unsafe_json}"

apply_coverage_json="$(php scripts/cleanup.php coverage --apply --json)"
php -r '
$payload = json_decode(stream_get_contents(STDIN), true);
if (!is_array($payload)) { fwrite(STDERR, "coverage apply output is not JSON\n"); exit(1); }
if (($payload["summary"]["deleted"] ?? null) !== 1) { fwrite(STDERR, "coverage apply deleted count mismatch\n"); exit(1); }
' <<< "${apply_coverage_json}"
if [[ -d "${artifacts_root}/coverage/back_php" ]]; then
  echo "coverage apply did not delete .testkit/coverage/back_php" >&2
  exit 1
fi

dry_json="$(php scripts/cleanup.php reports --max-runs=2 --dry-run --json)"

php -r '
$payload = json_decode(stream_get_contents(STDIN), true);
if (!is_array($payload)) { fwrite(STDERR, "dry-run output is not JSON\n"); exit(1); }
if (($payload["mode"] ?? null) !== "dry_run") { fwrite(STDERR, "dry-run mode mismatch\n"); exit(1); }
if (($payload["groups"]["reports"]["run_dirs_delete"] ?? null) !== 2) { fwrite(STDERR, "dry-run delete count mismatch\n"); exit(1); }
if (($payload["groups"]["reports"]["run_dirs_delete_by_max_runs"] ?? null) !== 2) { fwrite(STDERR, "dry-run max-runs count mismatch\n"); exit(1); }
if (($payload["summary"]["delete_candidates"] ?? null) !== 2) { fwrite(STDERR, "dry-run summary mismatch\n"); exit(1); }
' <<< "${dry_json}"

before_count="$(find "${runs_root}" -mindepth 1 -maxdepth 1 -type d | wc -l | tr -d ' ')"
if [[ "${before_count}" != "4" ]]; then
  echo "dry-run deleted run dirs unexpectedly: before_count=${before_count}" >&2
  exit 1
fi

apply_json="$(php scripts/cleanup.php reports --max-runs=2 --apply --json)"

php -r '
$payload = json_decode(stream_get_contents(STDIN), true);
if (!is_array($payload)) { fwrite(STDERR, "apply output is not JSON\n"); exit(1); }
if (($payload["mode"] ?? null) !== "apply") { fwrite(STDERR, "apply mode mismatch\n"); exit(1); }
if (($payload["groups"]["reports"]["run_dirs_delete"] ?? null) !== 2) { fwrite(STDERR, "apply delete count mismatch\n"); exit(1); }
if (($payload["groups"]["reports"]["run_dirs_delete_by_max_runs"] ?? null) !== 2) { fwrite(STDERR, "apply max-runs count mismatch\n"); exit(1); }
if (($payload["summary"]["deleted"] ?? null) !== 2) { fwrite(STDERR, "apply deleted count mismatch\n"); exit(1); }
' <<< "${apply_json}"

after_count="$(find "${runs_root}" -mindepth 1 -maxdepth 1 -type d | wc -l | tr -d ' ')"
if [[ "${after_count}" != "2" ]]; then
  echo "apply retained unexpected run dir count: after_count=${after_count}" >&2
  exit 1
fi

if [[ ! -d "${runs_root}/20260614T120000Z_newest" ]]; then
  echo "newest run was deleted unexpectedly" >&2
  exit 1
fi

if [[ ! -d "${runs_root}/20260614T115900Z_second" ]]; then
  echo "second newest run was deleted unexpectedly" >&2
  exit 1
fi

if [[ -d "${runs_root}/20260614T115800Z_third" ]]; then
  echo "third newest run should have been deleted" >&2
  exit 1
fi

if [[ -d "${runs_root}/20260614T115700Z_fourth" ]]; then
  echo "oldest run should have been deleted" >&2
  exit 1
fi

mkdir -p "${artifacts_root}/coverage/back_php"
printf '{"ok":true}\n' > "${artifacts_root}/coverage/back_php/coverage.json"
create_run '20260614T120100Z_newer' '202606141201.00'
printf '{}\n' > "${artifacts_root}/reports/meta_20260614_115900.json"
printf '{}\n' > "${artifacts_root}/reports/meta_20260614_120000.json"
touch -t 202606141159.00 "${artifacts_root}/reports/meta_20260614_115900.json"
touch -t 202606141200.00 "${artifacts_root}/reports/meta_20260614_120000.json"

prune_json="$(php scripts/cleanup.php all --prune-to-latest --apply --json)"
php -r '
$payload = json_decode(stream_get_contents(STDIN), true);
if (!is_array($payload)) { fwrite(STDERR, "prune output is not JSON\n"); exit(1); }
if (($payload["options"]["prune_to_latest"] ?? null) !== true) { fwrite(STDERR, "prune option missing\n"); exit(1); }
if (($payload["options"]["max_artifacts"] ?? null) !== 1) { fwrite(STDERR, "max_artifacts should be 1\n"); exit(1); }
' <<< "${prune_json}"

run_count="$(find "${runs_root}" -mindepth 1 -maxdepth 1 -type d | wc -l | tr -d ' ')"
if [[ "${run_count}" != "1" ]]; then
  echo "--prune-to-latest should retain one run dir, got ${run_count}" >&2
  exit 1
fi

if [[ -d "${artifacts_root}/coverage" ]]; then
  echo "--prune-to-latest should delete regenerable coverage root" >&2
  exit 1
fi

if [[ ! -f "${artifacts_root}/reports/meta_latest.json" ]]; then
  echo "meta_latest.json was deleted unexpectedly" >&2
  exit 1
fi

if [[ ! -f "${artifacts_root}/reports/cleanup/cleanup_latest.json" ]]; then
  echo "cleanup_latest.json was not written" >&2
  exit 1
fi

echo "Cleanup CLI smoke PASS"
