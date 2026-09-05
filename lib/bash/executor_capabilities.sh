#!/usr/bin/env bash

# Internal reusable executor capability probe for TestKit host-native bridges.
# Safe to source: it does not mutate persistent state and does not read host secrets.

testkit_executor_platform() {
  local kernel=""
  kernel="$(uname -s 2>/dev/null || true)"
  case "${kernel}" in
    Linux*) printf '%s\n' linux ;;
    Darwin*) printf '%s\n' macos ;;
    MINGW*|MSYS*|CYGWIN*) printf '%s\n' windows ;;
    *) printf '%s\n' unknown ;;
  esac
}

testkit_executor_capability_supported() {
  case "${1:-}" in
    linux|macos|windows|bash|git|php|python3|realpath|mktemp|flock|sha256sum|docker|docker-daemon|docker-compose|writable-tmp) return 0 ;;
    *) return 1 ;;
  esac
}

testkit_executor_capability_check() {
  local capability="${1:-}" platform tmp
  case "${capability}" in
    linux|macos|windows)
      platform="$(testkit_executor_platform)"
      [[ "${platform}" == "${capability}" ]]
      ;;
    bash|git|php|python3|realpath|mktemp|flock|sha256sum)
      command -v "${capability}" >/dev/null 2>&1
      ;;
    docker)
      command -v docker >/dev/null 2>&1
      ;;
    docker-daemon)
      command -v docker >/dev/null 2>&1 && docker info >/dev/null 2>&1
      ;;
    docker-compose)
      command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1
      ;;
    writable-tmp)
      command -v mktemp >/dev/null 2>&1 || return 1
      tmp="$(mktemp "${TMPDIR:-/tmp}/testkit-capability.XXXXXX" 2>/dev/null)" || return 1
      if ! printf 'probe\n' >"${tmp}" 2>/dev/null; then
        rm -f -- "${tmp}" >/dev/null 2>&1 || true
        return 1
      fi
      rm -f -- "${tmp}" >/dev/null 2>&1 || return 1
      ;;
    *) return 2 ;;
  esac
}

testkit_executor_capabilities_probe() {
  local required=("$@")
  local known=(linux macos windows bash git php python3 realpath mktemp flock sha256sum docker docker-daemon docker-compose writable-tmp)
  local capability status first=1 missing_first=1 required_first=1 blocked=0
  local platform
  platform="$(testkit_executor_platform)"

  for capability in "${required[@]}"; do
    if ! testkit_executor_capability_supported "${capability}"; then
      printf '{"schema":"testkit.executor-capabilities.v1","status":"ERROR","code":"unsupported_requirement","requirement":"%s"}\n' "${capability}"
      return 2
    fi
  done

  printf '{"schema":"testkit.executor-capabilities.v1","status":"'
  for capability in "${required[@]}"; do
    if ! testkit_executor_capability_check "${capability}"; then
      blocked=1
      break
    fi
  done
  if [[ "${blocked}" -eq 1 ]]; then printf 'BLOCKED'; else printf 'PASS'; fi
  printf '","platform":"%s","required":[' "${platform}"
  for capability in "${required[@]}"; do
    [[ "${required_first}" -eq 1 ]] || printf ','
    required_first=0
    printf '"%s"' "${capability}"
  done
  printf '],"checks":{'
  for capability in "${known[@]}"; do
    [[ "${first}" -eq 1 ]] || printf ','
    first=0
    if testkit_executor_capability_check "${capability}"; then status=PASS; else status=FAIL; fi
    printf '"%s":"%s"' "${capability}" "${status}"
  done
  printf '},"missing":['
  for capability in "${required[@]}"; do
    if ! testkit_executor_capability_check "${capability}"; then
      [[ "${missing_first}" -eq 1 ]] || printf ','
      missing_first=0
      printf '"%s"' "${capability}"
    fi
  done
  printf ']}\n'
  [[ "${blocked}" -eq 0 ]]
}
