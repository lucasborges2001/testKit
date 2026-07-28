#!/usr/bin/env bash
set -euo pipefail

testkit_doctor_contract_cli() {
  php "${TESTKIT_ROOT_HOST}/scripts/contract.php" "$@"
}

testkit_doctor_validate_target() {
  local target="${1:-}"
  [[ -z "${target}" ]] && return 0
  if testkit_doctor_contract_cli validate-target "${target}" >/dev/null 2>&1; then
    return 0
  fi
  echo "doctor: target no soportado '${target}'. Consultá: php scripts/contract.php --json" >&2
  return 1
}

testkit_doctor_known_target() {
  testkit_doctor_contract_cli validate-target "${1:-}" >/dev/null 2>&1
}

testkit_doctor_target_kind() {
  local kind
  kind="$(testkit_doctor_contract_cli target-kind "${1:-}" 2>/dev/null || true)"
  [[ -n "${kind}" ]] && printf '%s\n' "${kind}" || printf 'unknown\n'
}
