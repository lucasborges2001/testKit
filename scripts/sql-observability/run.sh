#!/usr/bin/env bash
set -euo pipefail

TESTKIT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
ROOT="${TK_REPO_ROOT:-${TESTKIT_PROJECT_ROOT:-$(pwd)}}"
export TK_REPO_ROOT="$ROOT"
CONFIG_FILE="${SQLOBS_HOST_CONFIG:-config/sql-observability/host.json}"
CONFIG_LOADER="$TESTKIT_ROOT/scripts/sql-observability/config.php"
DB_SCRIPT="$TESTKIT_ROOT/scripts/sql-observability/db.sh"
MANIFEST_SCRIPT="$TESTKIT_ROOT/scripts/sql-observability/run-manifest.php"
EVIDENCE_SCRIPT="$TESTKIT_ROOT/scripts/sql-observability/evidence.php"
PROFILE_STABILITY_SCRIPT="$TESTKIT_ROOT/scripts/sql-observability/profile-stability.php"
REPORT_SCRIPT="$TESTKIT_ROOT/scripts/sql-observability/report.php"
TESTKIT_BIN="$TESTKIT_ROOT/bin/testkit"
TESTKIT_ROOT="${TESTKIT_ROOT:-/workspace/testkit}"
RUNTIME_ROOT="$ROOT/.testkit/reports/sql-observability/.runtime"

usage() {
  cat <<'TXT'
testKit SQL observability

Usage:
  php runTest.php sql-observability --list
  TESTKIT_SQL_OBSERVABILITY_SCENARIO=<id|all> TESTKIT_SQL_OBSERVABILITY_REPETITIONS=<1..5> php runTest.php sql-observability

Exit codes: 0 success, 2 operational, 3 contract, 4 incomplete/incompatible evidence, 5 SQL gate blocked.
No operation commits, pushes, accepts, promotes, or replaces a baseline.
TXT
}

die() {
  local code="$1"; shift
  echo "ERROR[sqlobs_host] $*" >&2
  exit "$code"
}

safe_id() {
  local value="$1"
  [[ "$value" =~ ^[a-z0-9][a-z0-9._:-]{0,159}$ ]] || return 1
}

absolute_path() {
  local value="$1"
  if [[ "$value" = /* ]]; then
    printf '%s\n' "$value"
  else
    printf '%s/%s\n' "$ROOT" "${value#./}"
  fi
}

json_get() {
  local file="$1"
  local path="$2"
  php -r '
    $data=json_decode(file_get_contents($argv[1]),true,64,JSON_THROW_ON_ERROR);
    $value=$data;
    foreach(explode(".",$argv[2]) as $part){
      if($part===""){continue;}
      if(!is_array($value)||!array_key_exists($part,$value)){exit(3);}
      $value=$value[$part];
    }
    if(is_bool($value)){echo $value ? "true" : "false";}
    elseif(is_scalar($value)){echo (string)$value;}
    else{echo json_encode($value,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
  ' "$file" "$path"
}

git_value() {
  local directory="$1"
  local command="$2"
  if git -C "$directory" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    git -C "$directory" $command 2>/dev/null || printf 'unknown\n'
  else
    printf 'unknown\n'
  fi
}

sanitize_log() {
  local source="$1"
  local target="$2"
  local root_password="$3"
  local user_password="$4"
  sed \
    -e "s/${root_password//\//\\/}/[REDACTED_ROOT_PASSWORD]/g" \
    -e "s/${user_password//\//\\/}/[REDACTED_DB_PASSWORD]/g" \
    -e 's#mysql://[^[:space:]]*#[REDACTED_DSN]#g' \
    -e 's#password=[^[:space:]]*#password=[REDACTED]#gi' \
    "$source" > "$target"
  chmod 0640 "$target"
}

config_scenario() {
  local scenario="$1"
  local output="$2"
  php "$CONFIG_LOADER" scenario --config "$CONFIG_FILE" --id "$scenario" > "$output"
}

resolve_gate_mode() {
  local event_name="$1"
  local override="$2"
  local output="$3"
  local args=(event-mode --config "$CONFIG_FILE" --event "$event_name")
  if [[ -n "$override" ]]; then
    args+=(--mode "$override")
  fi
  php "$CONFIG_LOADER" "${args[@]}" > "$output"
}

list_operation() {
  php "$CONFIG_LOADER" list --config "$CONFIG_FILE"
}

run_operation() {
  local scenario=""
  local repetition=""
  local output=""
  local event_name="${GITHUB_EVENT_NAME:-workflow_dispatch}"
  local requested_mode=""
  local baseline_override=""

  while [[ $# -gt 0 ]]; do
    case "$1" in
      --scenario) scenario="${2:-}"; shift 2 ;;
      --repetition) repetition="${2:-}"; shift 2 ;;
      --output) output="${2:-}"; shift 2 ;;
      --event) event_name="${2:-}"; shift 2 ;;
      --gate-mode|--mode) requested_mode="${2:-}"; shift 2 ;;
      --baseline) baseline_override="${2:-}"; shift 2 ;;
      *) die 2 "Unknown run option: $1" ;;
    esac
  done

  safe_id "$scenario" || die 2 "Invalid scenario id."
  [[ "$repetition" =~ ^[1-5]$ ]] || die 2 "repetition must be 1..5."
  [[ -n "$output" ]] || die 2 "output is required."
  output="$(absolute_path "$output")"
  mkdir -p "$output"
  [[ ! -L "$output" ]] || die 3 "Output directory cannot be a symlink."

  local scenario_json mode_json
  scenario_json="$(mktemp)"
  mode_json="$(mktemp)"
  config_scenario "$scenario" "$scenario_json"
  resolve_gate_mode "$event_name" "$requested_mode" "$mode_json"

  local enabled
  enabled="$(json_get "$scenario_json" enabled)"
  [[ "$enabled" == "true" ]] || die 3 "Scenario is disabled."

  local test_match module_id scenario_context policy_rel baseline_rel baseline_status gate_rel allow_rel
  local dataset_manifest dataset_id dataset_version dataset_hash env_id engine_version timeout_minutes db_prefix
  local repository effective_mode mode_source
  test_match="$(json_get "$scenario_json" test_match)"
  module_id="$(json_get "$scenario_json" module_id)"
  scenario_context="$(json_get "$scenario_json" scenario_id)"
  policy_rel="$(json_get "$scenario_json" policy_file)"
  baseline_rel="$(json_get "$scenario_json" baseline_file)"
  baseline_status="$(json_get "$scenario_json" baseline_status)"
  gate_rel="$(json_get "$scenario_json" gate_file)"
  allow_rel="$(json_get "$scenario_json" allowlist_file)"
  dataset_manifest="$(json_get "$scenario_json" dataset)"
  dataset_id="$(json_get "$scenario_json" dataset_contract.dataset_id)"
  dataset_version="$(json_get "$scenario_json" dataset_contract.dataset_version)"
  dataset_hash="$(json_get "$scenario_json" dataset_contract.dataset_hash)"
  db_prefix="$(json_get "$scenario_json" dataset_contract.safety.database_name_prefix)"
  env_id="$(json_get "$scenario_json" defaults.environment_id)"
  engine_version="$(json_get "$scenario_json" defaults.engine_version)"
  timeout_minutes="$(json_get "$scenario_json" timeout_minutes)"
  repository="$(json_get "$scenario_json" project.repository)"
  effective_mode="$(json_get "$mode_json" effective_gate_mode)"
  mode_source="$(json_get "$mode_json" source)"

  local baseline_path=""
  if [[ -n "$baseline_override" ]]; then
    baseline_path="$(absolute_path "$baseline_override")"
    [[ -f "$baseline_path" ]] || die 2 "Baseline override does not exist."
    baseline_status="candidate_validation"
  elif [[ "$baseline_status" == "ready" ]]; then
    baseline_path="$ROOT/$baseline_rel"
    [[ -f "$baseline_path" ]] || die 3 "Ready baseline does not exist."
  fi

  mkdir -p "$RUNTIME_ROOT"
  local token run_id safe_scenario db_name compose_project port env_file
  token="$(date -u +%Y%m%d%H%M%S)-${GITHUB_RUN_ID:-local}-$$-$repetition"
  safe_scenario="${scenario//[^a-z0-9]/_}"
  run_id="sqlobs-${safe_scenario}-${token}"
  db_name="${db_prefix}${safe_scenario}_${repetition}_${RANDOM}"
  db_name="${db_name:0:63}"
  compose_project="sqlobs_${safe_scenario}_${repetition}_${RANDOM}"
  compose_project="${compose_project:0:50}"
  port="$((34000 + (RANDOM % 1500)))"
  env_file="$RUNTIME_ROOT/${run_id}.env"
  local root_password="sqlobs_root_${RANDOM}_${repetition}"
  local db_password="sqlobs_user_${RANDOM}_${repetition}"

  cat > "$env_file" <<ENV
APP_ENV=test
APP_DEBUG=0
COMPOSE_PROJECT_NAME=$compose_project
TESTKIT_STACK=mysql
TESTKIT_PHP_VERSION=8.4
TESTKIT_NODE_VERSION=20
TEST_MYSQL_ROOT_PASSWORD=$root_password
TEST_MYSQL_DB=$db_name
TEST_MYSQL_USER=sqlobs_user
TEST_MYSQL_PASSWORD=$db_password
TEST_MYSQL_PORT=$port
DB_DRIVER=mysql
DB_HOST=mysql_test
DB_PORT=3306
DB_DATABASE=$db_name
DB_NAME=$db_name
DB_USER=sqlobs_user
DB_USERNAME=sqlobs_user
DB_PASSWORD=$db_password
BASE_DB_HOST=mysql_test
BASE_DB_PORT=3306
BASE_DB_NAME=$db_name
BASE_DB_USER=sqlobs_user
BASE_DB_PASSWORD=$db_password
TEST_DB_DSN=mysql:host=mysql_test;port=3306;dbname=$db_name;charset=utf8mb4
TEST_DB_USER=sqlobs_user
TEST_DB_PASS=$db_password
TEST_JOBS=1
ENV
  chmod 0600 "$env_file"

  export APP_ENV=test
  export SQLOBS_ENV_FILE="$env_file"
  export SQLOBS_DATASET_MANIFEST="$dataset_manifest"
  export SQLOBS_DB_NAME="$db_name"
  export SQLOBS_DB_HOST="mysql_test"
  export SQLOBS_DB_ROOT_PASSWORD="$root_password"

  local cleanup_done=0
  cleanup_run() {
    if [[ "$cleanup_done" -eq 0 ]]; then
      bash "$DB_SCRIPT" cleanup >/dev/null 2>&1 || true
      cleanup_done=1
    fi
    rm -f "$env_file" "$scenario_json" "$mode_json"
  }
  trap cleanup_run EXIT

  local started finished start_epoch finish_epoch mysql_version
  started="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  start_epoch="$(date +%s)"
  local prepare_raw="$output/db-prepare.raw.log"
  local prepare_log="$output/db-prepare.log"
  local prepare_exit=0
  set +e
  mysql_version="$(bash "$DB_SCRIPT" prepare >"$prepare_raw" 2>&1; status=$?; cat "$prepare_raw"; exit "$status")"
  prepare_exit=$?
  set -e
  sanitize_log "$prepare_raw" "$prepare_log" "$root_password" "$db_password"
  rm -f "$prepare_raw"
  if [[ "$prepare_exit" -ne 0 ]]; then
    finished="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    echo "$prepare_exit" > "$output/db-prepare-exit-code.txt"
    cleanup_run
    trap - EXIT
    return "$prepare_exit"
  fi
  mysql_version="$(tail -n1 "$prepare_log" | tr -cd 'A-Za-z0-9._-')"
  [[ -n "$mysql_version" ]] || mysql_version="$engine_version"

  local commit branch base_commit testkit_commit
  commit="$(git_value "$ROOT" 'rev-parse HEAD')"
  branch="$(git_value "$ROOT" 'branch --show-current')"
  base_commit="$(git_value "$ROOT/submodules/Base" 'rev-parse HEAD')"
  testkit_commit="$(git_value "$ROOT/submodules/Base/testkit" 'rev-parse HEAD')"
  [[ -n "$branch" ]] || branch="detached"
  [[ -n "$requested_mode" ]] || requested_mode="$effective_mode"

  local container_output="/workspace/sqlobs-output"
  local container_policy="/workspace/project/$policy_rel"
  local container_gate="/workspace/project/$gate_rel"
  local container_allow="/workspace/project/$allow_rel"
  local container_baseline=""
  if [[ -n "$baseline_path" ]]; then
    if [[ "$baseline_path" == "$ROOT/"* ]]; then
      container_baseline="/workspace/project/${baseline_path#"$ROOT/"}"
    else
      cp "$baseline_path" "$output/baseline-input.json"
      container_baseline="$container_output/baseline-input.json"
    fi
  fi

  local run_raw="$output/scenario.raw.log"
  local run_log="$output/scenario.log"
  local suite_exit=0
  local command=(
    "$TESTKIT_BIN" run --rm
    -v "$output:$container_output"
    -e APP_ENV=test
    -e TEST_REQUIRE_TESTS=1
    -e TEST_FAIL_FAST=1
    -e TEST_JOBS=1
    -e TESTKIT_ARTIFACTS_ROOT="$container_output/testkit"
    -e TESTKIT_DB_PROFILE=1
    -e TESTKIT_DB_PROFILE_RUN_ID="$run_id"
    -e TESTKIT_DB_PROFILE_SUITE_ID=back_php
    -e TESTKIT_DB_PROFILE_TEST_ID="$test_match"
    -e TESTKIT_DB_PROFILE_MODULE_ID="$module_id"
    -e TESTKIT_DB_PROFILE_SCENARIO_ID="$scenario_context"
    -e TESTKIT_DB_PROFILE_RUNTIME_ID="$run_id"
    -e TESTKIT_DB_PROFILE_REPORT_PATH="$container_output/mysql_profile.json"
    -e TESTKIT_DB_PROFILE_HISTORY_PATH="$container_output/history/mysql_profile"
    -e TESTKIT_DB_PROFILE_SHARD_DIR="$container_output/shards"
    -e TESTKIT_DB_PROFILE_EXPLAIN=1
    -e TESTKIT_DB_PROFILE_EXPLAIN_DSN="mysql:host=mysql_test;port=3306;dbname=$db_name;charset=utf8mb4"
    -e TESTKIT_DB_PROFILE_EXPLAIN_USER=sqlobs_user
    -e TESTKIT_DB_PROFILE_EXPLAIN_PASS="$db_password"
    -e TESTKIT_DB_PROFILE_POLICY_FILE="$container_policy"
    -e TESTKIT_DB_PROFILE_POLICY_REPORT_PATH="$container_output/mysql_policy.json"
    -e TESTKIT_DB_PROFILE_POLICY_HISTORY_PATH="$container_output/history/mysql_policy"
    -e TESTKIT_DB_PROFILE_GATE_FILE="$container_gate"
    -e TESTKIT_DB_PROFILE_GATE_ALLOWLIST_FILE="$container_allow"
    -e TESTKIT_DB_PROFILE_GATE_MODE=report
    -e TESTKIT_DB_PROFILE_GATE_REPORT_PATH="$container_output/mysql_gate.json"
    -e TESTKIT_DB_PROFILE_GATE_HISTORY_PATH="$container_output/history/mysql_gate"
    -e TESTKIT_DB_PROFILE_GATE_JUNIT_PATH="$container_output/mysql_gate.junit.xml"
    -e TESTKIT_DB_PROFILE_GATE_SARIF_PATH="$container_output/mysql_gate.sarif"
    -e TESTKIT_DB_PROFILE_GATE_SUMMARY_PATH="$container_output/mysql_gate_summary.md"
    -e TESTKIT_DB_PROFILE_BASELINE_APPROVAL_REPORT_PATH="$container_output/mysql_baseline_approval.json"
    -e TESTKIT_DB_PROFILE_REPOSITORY="$repository"
    -e TESTKIT_DB_PROFILE_COMMIT_SHA="$commit"
    -e TESTKIT_DB_PROFILE_BRANCH="$branch"
    -e TESTKIT_DB_PROFILE_ENGINE_VERSION="$mysql_version"
    -e TESTKIT_DB_PROFILE_DATASET_ID="$dataset_id"
    -e TESTKIT_DB_PROFILE_DATASET_VERSION="$dataset_version"
    -e TESTKIT_DB_PROFILE_DATASET_HASH="$dataset_hash"
    -e TESTKIT_DB_PROFILE_ENVIRONMENT_ID="$env_id"
  )
  if [[ -n "$container_baseline" ]]; then
    command+=(
      -e TESTKIT_DB_PROFILE_BASELINE_FILE="$container_baseline"
      -e TESTKIT_DB_PROFILE_BASELINE_REPORT_PATH="$container_output/mysql_comparison.json"
      -e TESTKIT_DB_PROFILE_BASELINE_HISTORY_PATH="$container_output/history/mysql_comparison"
    )
  fi
  command+=(
    testkit php
    -d "auto_prepend_file=/workspace/testkit/utils/php/auto_prepend_mysql_profile.php"
    "/workspace/project/$test_match"
  )

  set +e
  TESTKIT_ENV_FILE="$env_file" \
  TESTKIT_STACK=mysql \
  TESTKIT_PROJECT_ROOT="$ROOT" \
  timeout "${timeout_minutes}m" "${command[@]}" >"$run_raw" 2>&1
  suite_exit=$?
  set -e
  sanitize_log "$run_raw" "$run_log" "$root_password" "$db_password"
  rm -f "$run_raw"
  echo "$suite_exit" > "$output/suite-exit-code.txt"

  local consolidate_exit=0
  if [[ "$suite_exit" -eq 0 ]]; then
    local consolidate_raw="$output/consolidate.raw.log"
    local baseline_env=()
    if [[ -n "$container_baseline" ]]; then
      baseline_env=(
        -e TESTKIT_DB_PROFILE_BASELINE_FILE="$container_baseline"
        -e TESTKIT_DB_PROFILE_BASELINE_REPORT_PATH="$container_output/mysql_comparison.json"
        -e TESTKIT_DB_PROFILE_BASELINE_HISTORY_PATH="$container_output/history/mysql_comparison"
      )
    fi
    set +e
    TESTKIT_ENV_FILE="$env_file" \
    TESTKIT_STACK=mysql \
    TESTKIT_PROJECT_ROOT="$ROOT" \
    "$TESTKIT_BIN" run --rm \
      -v "$output:$container_output" \
      -e APP_ENV=test \
      -e TESTKIT_ARTIFACTS_ROOT="$container_output/testkit" \
      -e TESTKIT_DB_PROFILE=1 \
      -e TESTKIT_DB_PROFILE_RUN_ID="$run_id" \
      -e TESTKIT_DB_PROFILE_REPORT_PATH="$container_output/mysql_profile.json" \
      -e TESTKIT_DB_PROFILE_HISTORY_PATH="$container_output/history/mysql_profile" \
      -e TESTKIT_DB_PROFILE_SHARD_DIR="$container_output/shards" \
      -e TESTKIT_DB_PROFILE_EXPLAIN=1 \
      -e TESTKIT_DB_PROFILE_EXPLAIN_DSN="mysql:host=mysql_test;port=3306;dbname=$db_name;charset=utf8mb4" \
      -e TESTKIT_DB_PROFILE_EXPLAIN_USER=sqlobs_user \
      -e TESTKIT_DB_PROFILE_EXPLAIN_PASS="$db_password" \
      -e TESTKIT_DB_PROFILE_POLICY_FILE="$container_policy" \
      -e TESTKIT_DB_PROFILE_POLICY_REPORT_PATH="$container_output/mysql_policy.json" \
      -e TESTKIT_DB_PROFILE_POLICY_HISTORY_PATH="$container_output/history/mysql_policy" \
      -e TESTKIT_DB_PROFILE_GATE_FILE="$container_gate" \
      -e TESTKIT_DB_PROFILE_GATE_ALLOWLIST_FILE="$container_allow" \
      -e TESTKIT_DB_PROFILE_GATE_MODE=report \
      -e TESTKIT_DB_PROFILE_GATE_REPORT_PATH="$container_output/mysql_gate.json" \
      -e TESTKIT_DB_PROFILE_GATE_HISTORY_PATH="$container_output/history/mysql_gate" \
      -e TESTKIT_DB_PROFILE_GATE_JUNIT_PATH="$container_output/mysql_gate.junit.xml" \
      -e TESTKIT_DB_PROFILE_GATE_SARIF_PATH="$container_output/mysql_gate.sarif" \
      -e TESTKIT_DB_PROFILE_GATE_SUMMARY_PATH="$container_output/mysql_gate_summary.md" \
      -e TESTKIT_DB_PROFILE_BASELINE_APPROVAL_REPORT_PATH="$container_output/mysql_baseline_approval.json" \
      -e TESTKIT_DB_PROFILE_REPOSITORY="$repository" \
      -e TESTKIT_DB_PROFILE_COMMIT_SHA="$commit" \
      -e TESTKIT_DB_PROFILE_BRANCH="$branch" \
      -e TESTKIT_DB_PROFILE_ENGINE_VERSION="$mysql_version" \
      -e TESTKIT_DB_PROFILE_DATASET_ID="$dataset_id" \
      -e TESTKIT_DB_PROFILE_DATASET_VERSION="$dataset_version" \
      -e TESTKIT_DB_PROFILE_DATASET_HASH="$dataset_hash" \
      -e TESTKIT_DB_PROFILE_ENVIRONMENT_ID="$env_id" \
      "${baseline_env[@]}" \
      testkit php /workspace/testkit/scripts/sql-observability/consolidate.php >"$consolidate_raw" 2>&1
    consolidate_exit=$?
    set -e
    sanitize_log "$consolidate_raw" "$output/consolidate.log" "$root_password" "$db_password"
    rm -f "$consolidate_raw"
  fi

  local verify_exit=0 cleanup_exit=0
  set +e
  bash "$DB_SCRIPT" verify >"$output/db-verify.raw.log" 2>&1
  verify_exit=$?
  set -e
  sanitize_log "$output/db-verify.raw.log" "$output/db-verify.log" "$root_password" "$db_password"
  rm -f "$output/db-verify.raw.log"

  set +e
  bash "$DB_SCRIPT" cleanup >/dev/null 2>&1
  cleanup_exit=$?
  set -e
  cleanup_done=1
  rm -f "$env_file"
  finished="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  finish_epoch="$(date +%s)"

  local manifest_args=(
    --output "$output/run-manifest.json"
    --run-id "$run_id"
    --repository "$repository"
    --commit-sha "$commit"
    --branch "$branch"
    --event-name "$event_name"
    --scenario-id "$scenario"
    --repetition "$repetition"
    --target "sql-observability"
    --test-match "$test_match"
    --module-id "$module_id"
    --dataset-id "$dataset_id"
    --dataset-version "$dataset_version"
    --dataset-hash "$dataset_hash"
    --environment-id "$env_id"
    --engine-version "$mysql_version"
    --testkit-commit "$testkit_commit"
    --base-commit "$base_commit"
    --docker-image "mysql:8.0"
    --baseline-status "$baseline_status"
    --requested-gate-mode "$requested_mode"
    --effective-gate-mode "report"
    --gate-mode-source "per_run_report_only"
    --started-at "$started"
    --finished-at "$finished"
    --exit "dataset_prepare=$prepare_exit"
    --exit "suite=$suite_exit"
    --exit "profile_consolidate=$consolidate_exit"
    --exit "dataset_verify=$verify_exit"
    --exit "cleanup=$cleanup_exit"
    --artifact "scenario_log=$run_log"
    --artifact "db_prepare_log=$prepare_log"
    --artifact "db_verify_log=$output/db-verify.log"
  )
  local artifact
  for artifact in \
    "mysql_profile=mysql_profile.json" \
    "mysql_policy=mysql_policy.json" \
    "mysql_comparison=mysql_comparison.json" \
    "mysql_gate=mysql_gate.json" \
    "junit=mysql_gate.junit.xml" \
    "sarif=mysql_gate.sarif" \
    "summary=mysql_gate_summary.md" \
    "baseline_approval=mysql_baseline_approval.json"; do
    local name="${artifact%%=*}"
    local relative="${artifact#*=}"
    if [[ -f "$output/$relative" ]]; then
      manifest_args+=(--artifact "$name=$output/$relative")
    fi
  done
  local suite_report=""
  if [[ -d "$output/testkit/reports" ]]; then
    suite_report="$(find "$output/testkit/reports" -maxdepth 1 -type f -name '*_latest.json' -print | sort | head -n1 || true)"
  fi
  if [[ -n "$suite_report" ]]; then
    manifest_args+=(--artifact "suite_report=$suite_report")
  fi
  if [[ "$baseline_status" != "ready" && "$baseline_status" != "candidate_validation" ]]; then
    manifest_args+=(--limitation "No real baseline is versioned for this scenario; comparison and temporal stability are unavailable.")
  fi
  if [[ "$suite_exit" -ne 0 ]]; then
    manifest_args+=(--limitation "The scenario suite exited non-zero; this repetition cannot confirm stability.")
  fi
  php "$MANIFEST_SCRIPT" "${manifest_args[@]}" > "$output/run-manifest.stdout.json"

  rm -f "$scenario_json" "$mode_json"
  trap - EXIT

  if [[ "$suite_exit" -ne 0 ]]; then
    return "$suite_exit"
  fi
  if [[ "$consolidate_exit" -ne 0 ]]; then
    return 2
  fi
  if [[ "$verify_exit" -ne 0 || "$cleanup_exit" -ne 0 ]]; then
    return 2
  fi
  return 0
}

gate_operation() {
  local scenario=""
  local evidence=""
  local runs=""
  local output=""
  local mode="report"

  while [[ $# -gt 0 ]]; do
    case "$1" in
      --scenario) scenario="${2:-}"; shift 2 ;;
      --evidence) evidence="${2:-}"; shift 2 ;;
      --runs) runs="${2:-}"; shift 2 ;;
      --output) output="${2:-}"; shift 2 ;;
      --mode|--gate-mode) mode="${2:-}"; shift 2 ;;
      *) die 2 "Unknown gate option: $1" ;;
    esac
  done
  safe_id "$scenario" || die 2 "Invalid scenario id."
  [[ "$mode" =~ ^(off|report|warn|fail)$ ]] || die 2 "Invalid gate mode."
  [[ -n "$evidence" ]] || die 2 "evidence is required."
  evidence="$(absolute_path "$evidence")"
  [[ -f "$evidence" ]] || die 2 "Evidence manifest does not exist."
  if [[ -z "$runs" ]]; then
    runs="$(dirname "$evidence")"
  fi
  runs="$(absolute_path "$runs")"
  if [[ -z "$output" ]]; then
    output="$(dirname "$evidence")/gate"
  fi
  output="$(absolute_path "$output")"
  mkdir -p "$output"

  local scenario_json
  scenario_json="$(mktemp)"
  config_scenario "$scenario" "$scenario_json"
  local repetitions gate_rel allow_rel
  repetitions="$(json_get "$scenario_json" repetitions)"
  gate_rel="$(json_get "$scenario_json" gate_file)"
  allow_rel="$(json_get "$scenario_json" allowlist_file)"
  local last_manifest="$runs/run-$repetitions/run-manifest.json"
  if [[ ! -f "$last_manifest" ]]; then
    last_manifest="$(find "$runs" -maxdepth 2 -type f -path '*/run-manifest.json' | sort | tail -n1 || true)"
  fi
  [[ -f "$last_manifest" ]] || die 2 "Last run manifest is missing."

  local profile_rel policy_rel comparison_rel
  profile_rel="$(json_get "$last_manifest" artifacts.mysql_profile)"
  policy_rel="$(json_get "$last_manifest" artifacts.mysql_policy)"
  local last_run_dir
  last_run_dir="$(dirname "$last_manifest")"
  profile_rel="$last_run_dir/$profile_rel"
  policy_rel="$last_run_dir/$policy_rel"
  comparison_rel=""
  if php -r '$d=json_decode(file_get_contents($argv[1]),true); exit(isset($d["artifacts"]["mysql_comparison"])?0:1);' "$last_manifest"; then
    comparison_rel="$(json_get "$last_manifest" artifacts.mysql_comparison)"
    comparison_rel="$last_run_dir/$comparison_rel"
  fi

  local gate_exit=0
  local args=(
    --profile "$profile_rel"
    --policy-report "$policy_rel"
    --evidence "$evidence"
    --gate "$ROOT/$gate_rel"
    --allowlist "$ROOT/$allow_rel"
    --mode "$mode"
    --format human
    --json "$output/mysql_gate.json"
    --junit "$output/mysql_gate.junit.xml"
    --sarif "$output/mysql_gate.sarif"
    --summary "$output/mysql_gate_summary.md"
  )
  set +e
  php "$TESTKIT_ROOT/scripts/query_gate.php" "${args[@]}" >"$output/gate.log" 2>&1
  gate_exit=$?
  set -e
  echo "$gate_exit" > "$output/gate-exit-code.txt"

  local approval_args=(
    --gate-report "$output/mysql_gate.json"
    --profile "$profile_rel"
    --json "$output/mysql_baseline_approval.json"
    --format human
  )
  if [[ -n "$comparison_rel" && -f "$comparison_rel" ]]; then
    approval_args+=(--comparison "$comparison_rel")
  fi
  set +e
  php "$TESTKIT_ROOT/scripts/query_baseline_approval.php" "${approval_args[@]}" >"$output/baseline-approval.log" 2>&1
  local approval_exit=$?
  set -e
  echo "$approval_exit" > "$output/baseline-approval-exit-code.txt"

  rm -f "$scenario_json"
  if [[ "$gate_exit" -ne 0 ]]; then
    return "$gate_exit"
  fi
  if [[ "$approval_exit" -ne 0 ]]; then
    return "$approval_exit"
  fi
  return 0
}

baseline_candidate_operation() {
  local scenario=""
  local output=""
  local event_name="workflow_dispatch"
  while [[ $# -gt 0 ]]; do
    case "$1" in
      --scenario) scenario="${2:-}"; shift 2 ;;
      --output) output="${2:-}"; shift 2 ;;
      --event) event_name="${2:-}"; shift 2 ;;
      *) die 2 "Unknown baseline-candidate option: $1" ;;
    esac
  done
  safe_id "$scenario" || die 2 "Invalid scenario id."
  [[ -n "$output" ]] || die 2 "output is required."
  output="$(absolute_path "$output")"
  mkdir -p "$output/runs"

  local scenario_json repetitions dataset_id dataset_version env_id repository baseline_rel
  scenario_json="$(mktemp)"
  config_scenario "$scenario" "$scenario_json"
  repetitions="$(json_get "$scenario_json" repetitions)"
  dataset_id="$(json_get "$scenario_json" dataset_contract.dataset_id)"
  dataset_version="$(json_get "$scenario_json" dataset_contract.dataset_version)"
  env_id="$(json_get "$scenario_json" defaults.environment_id)"
  repository="$(json_get "$scenario_json" project.repository)"
  baseline_rel="$(json_get "$scenario_json" baseline_file)"

  local repetition run_exit=0
  for repetition in $(seq 1 "$repetitions"); do
    set +e
    run_operation \
      --scenario "$scenario" \
      --repetition "$repetition" \
      --output "$output/runs/run-$repetition" \
      --event "$event_name" \
      --gate-mode report
    run_exit=$?
    set -e
    if [[ "$run_exit" -ne 0 ]]; then
      echo "$run_exit" > "$output/baseline-candidate-exit-code.txt"
      rm -f "$scenario_json"
      return "$run_exit"
    fi
  done

  set +e
  php "$EVIDENCE_SCRIPT" \
    --scenario "$scenario" \
    --runs "$output/runs" \
    --repetitions "$repetitions" \
    --output "$output/evidence.json" \
    --baseline-pending >"$output/evidence.log" 2>&1
  local evidence_exit=$?
  set -e

  set +e
  php "$PROFILE_STABILITY_SCRIPT" \
    --scenario "$scenario" \
    --runs "$output/runs" \
    --repetitions "$repetitions" \
    --output "$output/profile-stability.json" >"$output/profile-stability.log" 2>&1
  local stability_exit=$?
  set -e
  if [[ "$stability_exit" -ne 0 ]]; then
    echo "$stability_exit" > "$output/baseline-candidate-exit-code.txt"
    rm -f "$scenario_json"
    return "$stability_exit"
  fi

  local candidate="$output/${scenario}.candidate.json"
  local selected_profile="$output/runs/run-$repetitions/mysql_profile.json"
  local commit branch
  commit="$(git_value "$ROOT" 'rev-parse HEAD')"
  branch="$(git_value "$ROOT" 'branch --show-current')"
  [[ -n "$branch" ]] || branch="detached"

  php "$TESTKIT_ROOT/scripts/query_baseline.php" create \
    --profile "$selected_profile" \
    --output "$candidate" \
    --baseline-id "pruebas.${scenario}.v1" \
    --description "Candidate generated from explicit repetition $repetitions; human promotion required." \
    --repository "$repository" \
    --commit-sha "$commit" \
    --branch "$branch" \
    --engine-version "$(json_get "$output/runs/run-$repetitions/run-manifest.json" engine_version)" \
    --dataset-id "$dataset_id" \
    --dataset-version "$dataset_version" \
    --environment-id "$env_id" >"$output/baseline-candidate.log" 2>&1

  set +e
  run_operation \
    --scenario "$scenario" \
    --repetition 4 \
    --output "$output/validation" \
    --event "$event_name" \
    --gate-mode report \
    --baseline "$candidate"
  local validation_exit=$?
  set -e

  if [[ -f "$output/validation/mysql_comparison.json" ]]; then
    php "$TESTKIT_ROOT/scripts/query_comparison_report.php" \
      --current "$output/validation/mysql_profile.json" \
      --baseline "$candidate" \
      --format human >"$output/candidate-comparison.md" 2>&1 || true
  fi
  if [[ -f "$output/validation/mysql_gate.json" ]]; then
    php "$TESTKIT_ROOT/scripts/query_baseline_approval.php" \
      --gate-report "$output/validation/mysql_gate.json" \
      --comparison "$output/validation/mysql_comparison.json" \
      --profile "$output/validation/mysql_profile.json" \
      --json "$output/baseline-approval.json" \
      --format human >"$output/baseline-approval.log" 2>&1 || true
  fi

  cat >"$output/BASELINE_CANDIDATE_REVIEW.md" <<TXT
# SQL baseline candidate review

Scenario: \`$scenario\`

Candidate: \`$(basename "$candidate")\`

Selected source: explicit repetition \`$repetitions\`.

Versioned target (not modified): \`$baseline_rel\`.

Evidence manifest exit: \`$evidence_exit\`.

Profile stability exit: \`$stability_exit\`.

Validation run exit: \`$validation_exit\`.

This operation did not modify Git or the versioned baseline. Review manifests, comparison, gate and approval before copying the candidate in an explicit branch.
TXT
  echo "$validation_exit" > "$output/baseline-candidate-exit-code.txt"
  rm -f "$scenario_json"
  return "$validation_exit"
}

report_operation() {
  local report_root=".testkit/reports/sql-observability"
  local output=".testkit/reports/sql-observability/final/reports"
  local config="config/sql-observability/reporting.json"
  local generated_at=""
  local fail_on_blocked="0"
  local manifests=()
  local history_manifests=()
  while [[ $# -gt 0 ]]; do
    case "$1" in
      --root) report_root="${2:-}"; shift 2 ;;
      --output) output="${2:-}"; shift 2 ;;
      --config) config="${2:-}"; shift 2 ;;
      --manifest) manifests+=("${2:-}"); shift 2 ;;
      --history-manifest) history_manifests+=("${2:-}"); shift 2 ;;
      --generated-at) generated_at="${2:-}"; shift 2 ;;
      --fail-on-blocked) fail_on_blocked="1"; shift ;;
      *) die 2 "Unknown report option: $1" ;;
    esac
  done
  [[ -n "$report_root" ]] || die 2 "report root is required."
  [[ -n "$output" ]] || die 2 "report output is required."
  local args=(build --root "$report_root" --output "$output" --config "$config")
  local manifest
  for manifest in "${manifests[@]}"; do args+=(--manifest "$manifest"); done
  for manifest in "${history_manifests[@]}"; do args+=(--history-manifest "$manifest"); done
  if [[ -n "$generated_at" ]]; then args+=(--generated-at "$generated_at"); fi
  if [[ "$fail_on_blocked" == "1" ]]; then args+=(--fail-on-blocked); fi
  php "$REPORT_SCRIPT" "${args[@]}"
}

verify_operation() {
  local selection="all"
  local output=".testkit/reports/sql-observability"
  local event_name="${GITHUB_EVENT_NAME:-workflow_dispatch}"
  local mode=""
  local effective_mode=""
  local repetitions_override=""
  while [[ $# -gt 0 ]]; do
    case "$1" in
      --scenario) selection="${2:-}"; shift 2 ;;
      --output) output="${2:-}"; shift 2 ;;
      --event) event_name="${2:-}"; shift 2 ;;
      --gate-mode|--mode) mode="${2:-}"; shift 2 ;;
      --repetitions) repetitions_override="${2:-}"; shift 2 ;;
      *) die 2 "Unknown verify option: $1" ;;
    esac
  done
  output="$(absolute_path "$output")"
  mkdir -p "$output/preflight" "$output/scenarios" "$output/final"

  local config_exit=0
  set +e
  php "$CONFIG_LOADER" verify --config "$CONFIG_FILE" >"$output/preflight/host-config.json" 2>"$output/preflight/host-config.stderr.log"
  config_exit=$?
  set -e
  echo "$config_exit" >"$output/preflight/host-config-exit-code.txt"
  if [[ "$config_exit" -ne 0 ]]; then
    return "$config_exit"
  fi
  effective_mode="$mode"
  if [[ -z "$effective_mode" ]]; then
    local mode_json
    mode_json="$(mktemp)"
    resolve_gate_mode "$event_name" "" "$mode_json"
    effective_mode="$(json_get "$mode_json" effective_gate_mode)"
    rm -f "$mode_json"
  fi
  command -v docker >/dev/null 2>&1 || {
    echo "Docker is required for disposable MySQL." >"$output/preflight/docker-unavailable.txt"
    return 2
  }
  docker info >/dev/null 2>&1 || {
    echo "Docker daemon is unavailable." >"$output/preflight/docker-unavailable.txt"
    return 2
  }
  [[ -f "$TESTKIT_ROOT/scripts/query_gate.php" ]] || {
    echo "Phase 5 is unavailable." >"$output/preflight/phase5-unavailable.txt"
    return 2
  }

  local scenario_list=()
  if [[ "$selection" == "all" ]]; then
    mapfile -t scenario_list < <(php -r '
      $d=json_decode(file_get_contents($argv[1]),true,64,JSON_THROW_ON_ERROR);
      foreach($d["scenarios"] as $s){if(!empty($s["enabled"]))echo $s["id"],PHP_EOL;}
    ' "$output/preflight/host-config.json")
  else
    safe_id "$selection" || die 2 "Invalid scenario selection."
    scenario_list=("$selection")
  fi

  local overall=0 scenario
  for scenario in "${scenario_list[@]}"; do
    local scenario_json repetitions repetition scenario_root
    scenario_json="$(mktemp)"
    config_scenario "$scenario" "$scenario_json"
    repetitions="$(json_get "$scenario_json" repetitions)"
    if [[ -n "$repetitions_override" ]]; then
      [[ "$repetitions_override" =~ ^[1-5]$ ]] || die 2 "repetitions must be 1..5."
      repetitions="$repetitions_override"
    fi
    scenario_root="$output/scenarios/$scenario"
    mkdir -p "$scenario_root"
    for repetition in $(seq 1 "$repetitions"); do
      set +e
      local run_args=(
        --scenario "$scenario"
        --repetition "$repetition"
        --output "$scenario_root/run-$repetition"
        --event "$event_name"
      )
      if [[ -n "$mode" ]]; then
        run_args+=(--gate-mode "$mode")
      fi
      run_operation "${run_args[@]}"
      local run_exit=$?
      set -e
      if [[ "$run_exit" -ne 0 && "$overall" -eq 0 ]]; then
        overall="$run_exit"
      fi
    done
    local baseline_status
    baseline_status="$(json_get "$scenario_json" baseline_status)"
    local evidence_args=(
      --scenario "$scenario"
      --runs "$scenario_root"
      --repetitions "$repetitions"
      --output "$scenario_root/evidence.json"
    )
    if [[ "$baseline_status" != "ready" ]]; then
      evidence_args+=(--baseline-pending)
    fi
    set +e
    php "$EVIDENCE_SCRIPT" "${evidence_args[@]}" >"$scenario_root/evidence.log" 2>&1
    local evidence_exit=$?
    set -e
    if [[ "$evidence_exit" -ne 0 && "$overall" -eq 0 ]]; then
      overall="$evidence_exit"
    fi
    set +e
    gate_operation \
      --scenario "$scenario" \
      --runs "$scenario_root" \
      --evidence "$scenario_root/evidence.json" \
      --output "$scenario_root/gate" \
      --mode "$effective_mode"
    local gate_exit=$?
    set -e
    if [[ "$gate_exit" -ne 0 && "$overall" -eq 0 ]]; then
      overall="$gate_exit"
    fi
    if [[ "$baseline_status" != "ready" && "$overall" -eq 0 ]]; then
      overall=4
    fi
    rm -f "$scenario_json"
  done

  {
    echo "# SQL Observability Verify"
    echo
    echo "Scenarios: ${scenario_list[*]}"
    echo
    echo "Overall exit: \`$overall\`"
    echo
    echo "A value of 4 with successful runs means the host adoption is operational but a reviewed real baseline is still pending."
  } > "$output/final/summary.md"

  local report_exit=0
  set +e
  report_operation \
    --root "$output" \
    --output "$output/final/reports"
  report_exit=$?
  set -e
  echo "$report_exit" > "$output/final/report-exit-code.txt"
  if [[ "$report_exit" -ne 0 && "$overall" -eq 0 ]]; then
    overall="$report_exit"
  fi

  echo "$overall" > "$output/final/exit-code.txt"
  php -r '
    $root=$argv[1]; $exit=(int)$argv[2];
    $manifests=glob($root."/scenarios/*/run-*/run-manifest.json") ?: [];
    $scenarios=[];
    foreach ($manifests as $m) {
      $d=json_decode((string)file_get_contents($m), true);
      if (is_array($d) && isset($d["scenario_id"])) {$scenarios[$d["scenario_id"]]=true;}
    }
    $payload=[
      "suite_id"=>"sql_observability",
      "run_id"=>basename($root),
      "selected_test_count"=>count($manifests),
      "scenario_count"=>count($scenarios),
      "repetition_count"=>count($manifests),
      "suite_status"=>$exit===0 ? "passed" : "failed",
      "outcome_status"=>$exit===0 ? "passed" : ($exit===4 ? "pending_baseline" : "failed"),
      "process_exit_code"=>$exit,
      "scenario_results"=>array_keys($scenarios),
      "warnings"=>[],
      "failures"=>$exit===0 ? [] : [["message"=>"SQL Observability exit ".$exit]],
      "artifacts"=>["root"=>$root],
      "recommended_actions"=>[],
    ];
    file_put_contents($root."/suite-report.json", json_encode($payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
  ' "$output" "$overall"
  return "$overall"
}

main() {
  local operation="${1:-}"
  case "$operation" in
    list) shift; list_operation "$@" ;;
    run) shift; run_operation "$@" ;;
    gate) shift; gate_operation "$@" ;;
    baseline-candidate) shift; baseline_candidate_operation "$@" ;;
    verify) shift; verify_operation "$@" ;;
    report) shift; report_operation "$@" ;;
    --help|-h|help|"") usage ;;
    *) die 2 "Unknown operation: $operation" ;;
  esac
}

main "$@"
