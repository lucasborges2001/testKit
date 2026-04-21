#!/usr/bin/env bash
set -euo pipefail

declare -ag TESTKIT_DOCTOR_BASE_CHECKS=()
declare -ag TESTKIT_DOCTOR_CAPABILITY_CHECKS=()

TESTKIT_DOCTOR_BASE_STATUS="PASS"
TESTKIT_DOCTOR_CAPABILITY_STATUS="PASS"
TESTKIT_DOCTOR_MODE="${TESTKIT_DOCTOR_MODE:-full}"
TESTKIT_DOCTOR_TARGET=""
TESTKIT_DOCTOR_DUMP="0"

testkit_doctor_normalize_token() {
  echo "${1:-}" | tr '[:upper:]' '[:lower:]' | xargs
}

testkit_doctor_status_rank() {
  case "${1:-}" in
    PASS) echo 1 ;;
    WARN) echo 2 ;;
    UNKNOWN) echo 3 ;;
    FAIL) echo 4 ;;
    *) echo 0 ;;
  esac
}

testkit_doctor_update_status() {
  local scope="$1"
  local candidate="${2:-PASS}"
  local current_var
  local current_value
  local candidate_rank
  local current_rank

  case "${scope}" in
    base) current_var="TESTKIT_DOCTOR_BASE_STATUS" ;;
    capability) current_var="TESTKIT_DOCTOR_CAPABILITY_STATUS" ;;
    *) return 0 ;;
  esac

  current_value="${!current_var:-PASS}"
  candidate_rank="$(testkit_doctor_status_rank "${candidate}")"
  current_rank="$(testkit_doctor_status_rank "${current_value}")"
  if (( candidate_rank > current_rank )); then
    printf -v "${current_var}" '%s' "${candidate}"
  fi
}

testkit_doctor_reset_state() {
  TESTKIT_DOCTOR_BASE_CHECKS=()
  TESTKIT_DOCTOR_CAPABILITY_CHECKS=()
  TESTKIT_DOCTOR_BASE_STATUS="PASS"
  TESTKIT_DOCTOR_CAPABILITY_STATUS="PASS"
}

testkit_doctor_add_check() {
  local scope="$1"
  local status="$2"
  local code="$3"
  local summary="$4"
  local action="${5:-}"
  local encoded="${status}|${code}|${summary}|${action}"

  testkit_doctor_update_status "${scope}" "${status}"

  case "${scope}" in
    base) TESTKIT_DOCTOR_BASE_CHECKS+=("${encoded}") ;;
    capability) TESTKIT_DOCTOR_CAPABILITY_CHECKS+=("${encoded}") ;;
  esac
}

testkit_doctor_env_any() {
  local key
  for key in "$@"; do
    local value="${!key:-}"
    if [[ -n "${value//[[:space:]]/}" ]]; then
      echo "${value}"
      return 0
    fi
  done
  return 1
}

testkit_doctor_env_present_any() {
  testkit_doctor_env_any "$@" >/dev/null 2>&1
}

testkit_doctor_snapshot_source_visible() {
  testkit_doctor_env_present_any     TEST_BASELINE_SNAPSHOT_FILE     TEST_BASELINE_SNAPSHOT_METADATA     TEST_BASELINE_SNAPSHOT_REPORT     TEST_BASELINE_SNAPSHOT_JSON
}

testkit_doctor_validate_target() {
  local target="$1"
  [[ -z "${target}" ]] && return 0

  case "${target}" in
    all|back|front|public_html|back-php|back-py|back-python|python|py|front-php|front-js|php|js|smoke|perf|stress|contract|critical|slow|migration-contract|migration|migrations)
      return 0
      ;;
    *)
      echo "doctor: target no soportado '${target}'. Usá uno de: all|back|front|back-php|back-py|back-python|front-php|front-js|php|js|smoke|perf|stress|contract|critical|slow|migration-contract." >&2
      return 1
      ;;
  esac
}

testkit_doctor_parse_args() {
  TESTKIT_DOCTOR_MODE="$(testkit_doctor_normalize_token "${TESTKIT_DOCTOR_MODE:-full}")"
  TESTKIT_DOCTOR_TARGET=""
  TESTKIT_DOCTOR_DUMP="0"

  local arg
  for arg in "$@"; do
    case "${arg}" in
      --dump)
        TESTKIT_DOCTOR_DUMP="1"
        ;;
      --compact)
        TESTKIT_DOCTOR_MODE="compact"
        ;;
      --full)
        TESTKIT_DOCTOR_MODE="full"
        ;;
      --mode=compact)
        TESTKIT_DOCTOR_MODE="compact"
        ;;
      --mode=full)
        TESTKIT_DOCTOR_MODE="full"
        ;;
      --target=*)
        TESTKIT_DOCTOR_TARGET="$(testkit_doctor_normalize_token "${arg#--target=}")"
        testkit_doctor_validate_target "${TESTKIT_DOCTOR_TARGET}" || return 1
        ;;
      --*)
        echo "doctor: opción no soportada '${arg}'. Usá --full, --compact, --dump o --target=<target>." >&2
        return 1
        ;;
      *)
        if [[ -z "${TESTKIT_DOCTOR_TARGET}" ]]; then
          TESTKIT_DOCTOR_TARGET="$(testkit_doctor_normalize_token "${arg}")"
          testkit_doctor_validate_target "${TESTKIT_DOCTOR_TARGET}" || return 1
        else
          echo "doctor: argumento extra no soportado '${arg}'." >&2
          return 1
        fi
        ;;
    esac
  done

  if [[ "${TESTKIT_DOCTOR_MODE}" != "compact" && "${TESTKIT_DOCTOR_MODE}" != "full" ]]; then
    TESTKIT_DOCTOR_MODE="full"
  fi
}
