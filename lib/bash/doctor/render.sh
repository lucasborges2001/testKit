#!/usr/bin/env bash
set -euo pipefail

testkit_doctor__print_check() {
  local encoded="$1"
  IFS='|' read -r status code summary action <<< "${encoded}"
  echo "[${status}] ${code} - ${summary}"
  if [[ -n "${action}" ]]; then
    echo "       Acción: ${action}"
  fi
}

testkit_doctor__counts() {
  local array_name="$1"
  local -n ref="$array_name"
  local pass=0 warn=0 unknown=0 fail=0
  local encoded
  for encoded in "${ref[@]}"; do
    IFS='|' read -r status _ <<< "${encoded}"
    case "${status}" in
      PASS) ((pass+=1)) ;;
      WARN) ((warn+=1)) ;;
      UNKNOWN) ((unknown+=1)) ;;
      FAIL) ((fail+=1)) ;;
    esac
  done
  echo "${pass}|${warn}|${unknown}|${fail}"
}

testkit_doctor_render_full() {
  echo ""
  echo "== TESTKIT DOCTOR =="
  echo "[INFO] mode=${TESTKIT_DOCTOR_MODE}"
  echo "[INFO] target=${TESTKIT_DOCTOR_TARGET:-generic}"
  echo "[INFO] TESTKIT_STACK=${TESTKIT_STACK_EFFECTIVE:-<empty>}"
  echo "[INFO] TESTKIT_STACK_ORIGIN=$(testkit_doctor_origin TESTKIT_STACK)"
  echo "[INFO] TEST_STORE_DRIVER=${TEST_STORE_DRIVER:-<unset>} origin=$(testkit_doctor_origin TEST_STORE_DRIVER)"
  echo "[INFO] TEST_STORE_PROVISION=${TEST_STORE_PROVISION:-<unset>} origin=$(testkit_doctor_origin TEST_STORE_PROVISION)"
  echo "[INFO] COMPOSE_PROJECT_NAME=${COMPOSE_PROJECT_NAME:-<unset>} origin=$(testkit_doctor_origin COMPOSE_PROJECT_NAME)"
  echo "[INFO] COMPOSE_FILE=${COMPOSE_FILE:-<unset>} origin=$(testkit_doctor_origin COMPOSE_FILE)"
  echo "[INFO] TESTKIT_ROOT(host)=${TESTKIT_ROOT_HOST}"
  echo "[INFO] TESTKIT_PROJECT_ROOT(host)=${PROJECT_ROOT}"
  echo "[INFO] TESTKIT_HOST_UID:GID=${TESTKIT_HOST_UID}:${TESTKIT_HOST_GID}"

  echo ""
  echo "== BASE CHECKS =="
  local encoded
  for encoded in "${TESTKIT_DOCTOR_BASE_CHECKS[@]}"; do
    testkit_doctor__print_check "${encoded}"
  done
  echo "Base doctor: ${TESTKIT_DOCTOR_BASE_STATUS}"

  if [[ ${#TESTKIT_DOCTOR_CAPABILITY_CHECKS[@]} -gt 0 ]]; then
    echo ""
    echo "== CAPABILITY DOCTOR =="
    for encoded in "${TESTKIT_DOCTOR_CAPABILITY_CHECKS[@]}"; do
      testkit_doctor__print_check "${encoded}"
    done
    echo "Capability doctor: ${TESTKIT_DOCTOR_CAPABILITY_STATUS}"
    echo "Nota: capability no cambia el exit code del wrapper; el exit sigue atado al doctor base."
  fi

  echo ""
  if [[ "${TESTKIT_DOCTOR_BASE_STATUS}" != "FAIL" ]]; then
    echo "Doctor: OK"
  else
    echo "Doctor: FAIL (ver arriba)"
  fi
}

testkit_doctor_render_compact() {
  echo ""
  echo "== TESTKIT DOCTOR =="
  echo "[INFO] mode=${TESTKIT_DOCTOR_MODE} target=${TESTKIT_DOCTOR_TARGET:-generic}"
  echo "[INFO] infra=${TESTKIT_STACK_EFFECTIVE:-<empty>} stack_origin=$(testkit_doctor_origin TESTKIT_STACK) store=${TEST_STORE_DRIVER:-<unset>} store_origin=$(testkit_doctor_origin TEST_STORE_DRIVER)"

  local base_counts
  base_counts="$(testkit_doctor__counts TESTKIT_DOCTOR_BASE_CHECKS)"
  IFS='|' read -r base_pass base_warn base_unknown base_fail <<< "${base_counts}"
  echo "Base: status=${TESTKIT_DOCTOR_BASE_STATUS} pass=${base_pass} warn=${base_warn} unknown=${base_unknown} fail=${base_fail}"

  local cap_counts
  if [[ ${#TESTKIT_DOCTOR_CAPABILITY_CHECKS[@]} -gt 0 ]]; then
    cap_counts="$(testkit_doctor__counts TESTKIT_DOCTOR_CAPABILITY_CHECKS)"
    IFS='|' read -r cap_pass cap_warn cap_unknown cap_fail <<< "${cap_counts}"
    echo "Capability: status=${TESTKIT_DOCTOR_CAPABILITY_STATUS} pass=${cap_pass} warn=${cap_warn} unknown=${cap_unknown} fail=${cap_fail}"
  fi

  echo ""
  echo "Problemas relevantes:"
  local encoded status code summary action printed=0
  for encoded in "${TESTKIT_DOCTOR_BASE_CHECKS[@]}"; do
    IFS='|' read -r status code summary action <<< "${encoded}"
    if [[ "${status}" == "FAIL" || "${status}" == "WARN" ]]; then
      testkit_doctor__print_check "${encoded}"
      printed=1
    fi
  done
  for encoded in "${TESTKIT_DOCTOR_CAPABILITY_CHECKS[@]}"; do
    IFS='|' read -r status code summary action <<< "${encoded}"
    if [[ "${status}" == "FAIL" || "${status}" == "WARN" || "${status}" == "UNKNOWN" ]]; then
      testkit_doctor__print_check "${encoded}"
      printed=1
    fi
  done
  if [[ "${printed}" -eq 0 ]]; then
    echo "[PASS] NO_RELEVANT_WARNINGS - no hay warnings/fails visibles en modo compacto."
  fi

  echo ""
  if [[ "${TESTKIT_DOCTOR_BASE_STATUS}" != "FAIL" ]]; then
    echo "Doctor: OK"
  else
    echo "Doctor: FAIL (ver arriba)"
  fi
}

testkit_doctor_origin() {
  local wanted="$1"
  local encoded key from to current file_value
  for encoded in "${TESTKIT_ENV_OVERRIDES[@]}"; do
    IFS='|' read -r key from to current file_value <<< "${encoded}"
    if [[ "${key}" == "${wanted}" ]]; then
      if [[ "${TESTKIT_ALLOW_ENV_OVERRIDES:-0}" == "1" ]]; then
        echo "process(explicit)"
      else
        echo "process"
      fi
      return 0
    fi
  done
  if testkit_env_file_declares "${wanted}"; then
    echo "file"
  elif [[ -v "${wanted}" ]]; then
    echo "process"
  else
    echo "unset"
  fi
}

testkit_doctor_render_dump() {
  echo ""
  echo "-- Effective TestKit config --"
  echo "TESTKIT_DOCTOR_MODE: ${TESTKIT_DOCTOR_MODE}"
  echo "TESTKIT_DOCTOR_TARGET: ${TESTKIT_DOCTOR_TARGET}"
  echo "TESTKIT_STACK: ${TESTKIT_STACK_EFFECTIVE:-<empty>}"
  echo "projectRoot: ${PROJECT_ROOT}"
  echo "testkitRootHost: ${TESTKIT_ROOT_HOST}"
  echo "envFile: ${ENV_FILE}"
  echo "DB_ENV_PATH(in-container): ${TESTKIT_DB_ENV_PATH}"
  echo "TESTKIT_DOCTOR_BASE_STATUS: ${TESTKIT_DOCTOR_BASE_STATUS}"
  echo "TESTKIT_CAPABILITY_STATUS: ${TESTKIT_DOCTOR_CAPABILITY_STATUS}"
  echo ""

  echo "TESTKIT_DOCTOR_BASE_CHECK_COUNT: ${#TESTKIT_DOCTOR_BASE_CHECKS[@]}"
  local i=1 encoded
  for encoded in "${TESTKIT_DOCTOR_BASE_CHECKS[@]}"; do
    IFS='|' read -r status code summary action <<< "${encoded}"
    echo "TESTKIT_DOCTOR_BASE_CHECK_${i}_STATUS: ${status}"
    echo "TESTKIT_DOCTOR_BASE_CHECK_${i}_CODE: ${code}"
    echo "TESTKIT_DOCTOR_BASE_CHECK_${i}_SUMMARY: ${summary}"
    echo "TESTKIT_DOCTOR_BASE_CHECK_${i}_ACTION: ${action}"
    ((i+=1))
  done

  echo "TESTKIT_CAPABILITY_CHECK_COUNT: ${#TESTKIT_DOCTOR_CAPABILITY_CHECKS[@]}"
  i=1
  for encoded in "${TESTKIT_DOCTOR_CAPABILITY_CHECKS[@]}"; do
    IFS='|' read -r status code summary action <<< "${encoded}"
    echo "TESTKIT_CAPABILITY_CHECK_${i}_STATUS: ${status}"
    echo "TESTKIT_CAPABILITY_CHECK_${i}_CODE: ${code}"
    echo "TESTKIT_CAPABILITY_CHECK_${i}_SUMMARY: ${summary}"
    echo "TESTKIT_CAPABILITY_CHECK_${i}_ACTION: ${action}"
    ((i+=1))
  done
}
