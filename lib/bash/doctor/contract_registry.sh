#!/usr/bin/env bash
set -euo pipefail

TESTKIT_DOCTOR_SELECTOR_KIND=""

_testkit_doctor_contract_cli() {
  php "${TESTKIT_ROOT_HOST}/scripts/contract.php" "$@"
}

testkit_doctor_validate_selector() {
  local kind="${1:-}"
  local name="${2:-}"
  [[ -z "${kind}" && -z "${name}" ]] && return 0
  if [[ -z "${kind}" || -z "${name}" ]]; then
    echo "doctor: selector incompleto; usá --suite, --group o --category." >&2
    return 1
  fi
  if _testkit_doctor_contract_cli validate-selector "${kind}" "${name}" >/dev/null 2>&1; then
    return 0
  fi
  echo "doctor: selector no soportado '${kind}:${name}'. Consultá: php scripts/contract.php --json" >&2
  return 1
}

# Compatibility only for the internal capability checker name; no public target alias remains.
testkit_doctor_validate_target() {
  testkit_doctor_validate_selector "${TESTKIT_DOCTOR_SELECTOR_KIND:-}" "${1:-}"
}

testkit_doctor_known_target() {
  testkit_doctor_validate_selector "${TESTKIT_DOCTOR_SELECTOR_KIND:-}" "${1:-}" >/dev/null 2>&1
}

testkit_doctor_target_kind() {
  case "${TESTKIT_DOCTOR_SELECTOR_KIND:-}" in
    suite) echo "suite" ;;
    group) echo "aggregate" ;;
    category) echo "category" ;;
    *) echo "unknown" ;;
  esac
}

testkit_doctor_parse_args() {
  TESTKIT_DOCTOR_MODE="$(testkit_doctor_normalize_token "${TESTKIT_DOCTOR_MODE:-full}")"
  TESTKIT_DOCTOR_TARGET=""
  TESTKIT_DOCTOR_SELECTOR_KIND=""
  TESTKIT_DOCTOR_DUMP="0"

  while [[ $# -gt 0 ]]; do
    local arg="$1"
    shift
    case "${arg}" in
      --dump) TESTKIT_DOCTOR_DUMP="1" ;;
      --compact) TESTKIT_DOCTOR_MODE="compact" ;;
      --full) TESTKIT_DOCTOR_MODE="full" ;;
      --mode=compact) TESTKIT_DOCTOR_MODE="compact" ;;
      --mode=full) TESTKIT_DOCTOR_MODE="full" ;;
      --readonly)
        echo "doctor: --readonly no está implementado en Bash." >&2
        return 1
        ;;
      --suite|--group|--category)
        [[ $# -gt 0 ]] || { echo "doctor: ${arg} exige valor." >&2; return 1; }
        [[ -z "${TESTKIT_DOCTOR_SELECTOR_KIND}" ]] || {
          echo "doctor: declaraste más de un selector." >&2
          return 1
        }
        TESTKIT_DOCTOR_SELECTOR_KIND="${arg#--}"
        TESTKIT_DOCTOR_TARGET="$(testkit_doctor_normalize_token "$1")"
        shift
        ;;
      --suite=*|--group=*|--category=*)
        [[ -z "${TESTKIT_DOCTOR_SELECTOR_KIND}" ]] || {
          echo "doctor: declaraste más de un selector." >&2
          return 1
        }
        TESTKIT_DOCTOR_SELECTOR_KIND="${arg%%=*}"
        TESTKIT_DOCTOR_SELECTOR_KIND="${TESTKIT_DOCTOR_SELECTOR_KIND#--}"
        TESTKIT_DOCTOR_TARGET="$(testkit_doctor_normalize_token "${arg#*=}")"
        ;;
      --target=*|--target)
        echo "doctor: --target fue eliminado; usá --suite, --group o --category." >&2
        return 1
        ;;
      --*)
        echo "doctor: opción no soportada '${arg}'." >&2
        return 1
        ;;
      *)
        echo "doctor: no se aceptan selectores posicionales '${arg}'." >&2
        return 1
        ;;
    esac
  done

  if [[ "${TESTKIT_DOCTOR_MODE}" != "compact" && "${TESTKIT_DOCTOR_MODE}" != "full" ]]; then
    TESTKIT_DOCTOR_MODE="full"
  fi

  testkit_doctor_validate_selector "${TESTKIT_DOCTOR_SELECTOR_KIND}" "${TESTKIT_DOCTOR_TARGET}" || return 1
}
