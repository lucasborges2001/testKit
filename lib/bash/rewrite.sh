#!/usr/bin/env bash
set -euo pipefail

testkit_quote_sq() {
  local v="$1"
  printf "%s" "${v//\'/\'\\\'\'}"
}

testkit_detect_shell_kind() {
  case "${TESTKIT_WRAPPER_KIND:-}" in
    bash|powershell|direct) echo "${TESTKIT_WRAPPER_KIND}" ;;
    *)
      if [[ -n "${PSModulePath:-}" && -n "${WINDIR:-}" ]]; then
        echo "powershell"
      else
        echo "bash"
      fi
      ;;
  esac
}

testkit_suggest_rerun_command() {
  local target="$1"
  local file="$2"
  local shell_kind
  shell_kind="$(testkit_detect_shell_kind)"
  local qfile
  qfile="$(testkit_quote_sq "${file}")"

  case "${shell_kind}" in
    powershell)
      printf "%s" ".\\bin\\testkit.ps1 run --rm -e TEST_MATCH='${qfile}' testkit php runTest.php ${target}"
      ;;
    bash)
      printf "%s" "./bin/testkit run --rm -e TEST_MATCH='${qfile}' testkit php runTest.php ${target}"
      ;;
    *)
      printf "%s" "TEST_MATCH='${qfile}' php runTest.php ${target}"
      ;;
  esac
}

testkit_suggest_report_command() {
  local shell_kind
  shell_kind="$(testkit_detect_shell_kind)"
  case "${shell_kind}" in
    powershell) printf "%s" ".\\bin\\testkit.ps1 run --rm testkit php scripts/report.php" ;;
    bash) printf "%s" "./bin/testkit run --rm testkit php scripts/report.php" ;;
    *) printf "%s" "php scripts/report.php" ;;
  esac
}

testkit_suggest_list_command() {
  local target="$1"
  local shell_kind
  shell_kind="$(testkit_detect_shell_kind)"
  case "${shell_kind}" in
    powershell) printf "%s" ".\\bin\\testkit.ps1 run --rm testkit php runTest.php ${target} --list" ;;
    bash) printf "%s" "./bin/testkit run --rm testkit php runTest.php ${target} --list" ;;
    *) printf "%s" "php runTest.php ${target} --list" ;;
  esac
}

testkit_suggest_trace_command() {
  local target="$1"
  local shell_kind
  shell_kind="$(testkit_detect_shell_kind)"
  case "${shell_kind}" in
    powershell) printf "%s" ".\\bin\\testkit.ps1 run --rm -e TESTKIT_TRACE_MIGRATIONS=1 testkit php runTest.php ${target}" ;;
    bash) printf "%s" "./bin/testkit run --rm -e TESTKIT_TRACE_MIGRATIONS=1 testkit php runTest.php ${target}" ;;
    *) printf "%s" "TESTKIT_TRACE_MIGRATIONS=1 php runTest.php ${target}" ;;
  esac
}

testkit_rewrite_run_command_args() {
  local -a args=("$@")
  if [[ ${#args[@]} -eq 0 || "${args[0]}" != "run" ]]; then
    printf '%s\0' "${args[@]}"
    return 0
  fi

  local -a rewritten=()
  local saw_testkit=0
  local saw_container=0
  local wrapper_kind="${TESTKIT_WRAPPER_KIND:-bash}"
  local has_build_flag=0

  for arg in "${args[@]}"; do
    if [[ "${arg}" == "--build" || "${arg}" == "--no-build" ]]; then
      has_build_flag=1
      break
    fi
  done

  local idx=0
  for arg in "${args[@]}"; do
    if [[ $idx -eq 0 && "${arg}" == "run" ]]; then
      rewritten+=("${arg}")
      if [[ "${TESTKIT_RUN_BUILD:-1}" != "0" && $has_build_flag -eq 0 ]]; then
        rewritten+=("--build")
      fi
      idx=$((idx + 1))
      continue
    fi

    if [[ "${arg}" == "testkit" && $saw_container -eq 0 ]]; then
      rewritten+=("-e" "TESTKIT_WRAPPER_KIND=${wrapper_kind}")
      saw_testkit=1
      saw_container=1
      rewritten+=("${arg}")
      idx=$((idx + 1))
      continue
    fi

    if [[ $saw_testkit -eq 1 ]]; then
      case "${arg}" in
        runTest.php|./runTest.php|/workspace/project/runTest.php|/workspace/testkit/runTest.php)
          rewritten+=("/workspace/testkit/runTest.php"); continue ;;
        scripts/report.php|./scripts/report.php|/workspace/project/scripts/report.php|/workspace/testkit/scripts/report.php)
          rewritten+=("/workspace/testkit/scripts/report.php"); continue ;;
        scripts/query_report.php|./scripts/query_report.php|/workspace/project/scripts/query_report.php|/workspace/testkit/scripts/query_report.php)
          rewritten+=("/workspace/testkit/scripts/query_report.php"); continue ;;
        scripts/inspect.php|./scripts/inspect.php|/workspace/project/scripts/inspect.php|/workspace/testkit/scripts/inspect.php)
          rewritten+=("/workspace/testkit/scripts/inspect.php"); continue ;;
      esac
    fi

    rewritten+=("${arg}")
    idx=$((idx + 1))
  done

  printf '%s\0' "${rewritten[@]}"
}
