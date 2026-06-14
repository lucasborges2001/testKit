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
  local dir="${runs_root}/${name}"
  mkdir -p "${dir}"
  printf '{}\n' > "${dir}/meta_latest.json"
  printf '{}\n' > "${dir}/meta_20260614_120000.json"
}

create_run '20260614T120000Z_newest'
create_run '20260614T115900Z_second'
create_run '20260614T115800Z_third'
create_run '20260614T115700Z_fourth'

printf '{}\n' > "${artifacts_root}/reports/meta_latest.json"

export TESTKIT_PROJECT_ROOT="${host_root}"
export TK_REPO_ROOT="${host_root}"
export TESTKIT_ARTIFACTS_ROOT="${artifacts_root}"
unset TEST_COVERAGE_DIR || true

dry_json="$(php scripts/cleanup.php reports --max-runs=2 --dry-run --json)"

php -r '
$payload = json_decode(stream_get_contents(STDIN), true);
if (!is_array($payload)) { fwrite(STDERR, "dry-run output is not JSON\n"); exit(1); }
if (($payload["mode"] ?? null) !== "dry_run") { fwrite(STDERR, "dry-run mode mismatch\n"); exit(1); }
if (($payload["groups"]["reports"]["run_dirs_delete"] ?? null) !== 2) { fwrite(STDERR, "dry-run delete count mismatch\n"); exit(1); }
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

if [[ ! -f "${artifacts_root}/reports/meta_latest.json" ]]; then
  echo "meta_latest.json was deleted unexpectedly" >&2
  exit 1
fi

if [[ ! -f "${artifacts_root}/reports/cleanup/cleanup_latest.json" ]]; then
  echo "cleanup_latest.json was not written" >&2
  exit 1
fi

echo "Cleanup CLI smoke PASS"
