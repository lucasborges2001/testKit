#!/usr/bin/env bash
set -euo pipefail

# Host-side cleanup for stale TestKit Docker runtimes.
# A runtime is eligible only when a database container explicitly labeled by
# TestKit exists for its Compose project, the youngest database container is
# older than the TTL, and no Compose one-off container is currently running.

testkit_runtime_cleanup_usage() {
  cat <<'TXT'
Usage:
  testkit cleanup runtime [--older-than=4h] [--dry-run]
  testkit cleanup runtime [--older-than=4h] --apply --force

Options:
  --older-than=<N>[s|m|h|d]  Minimum runtime age. Default: 4h.
  --dry-run                  Plan only. Default.
  --apply                    Delete eligible TestKit runtimes.
  --force                    Required with --apply because DB volumes are removed.
  --json                     Print the cleanup audit as JSON.
  --quiet                    Suppress normal text output; audit is still written.

Safety:
  - Only Compose projects discovered from DB containers labeled io.testkit.runtime=true
    and io.testkit.resource=database are considered.
  - Running Compose one-off containers protect the whole project.
  - Cleanup removes project containers, Compose project volumes and project networks.
TXT
}

testkit_runtime_age_to_seconds() {
  local raw="${1:-}"
  if [[ ! "${raw}" =~ ^([0-9]+)([smhd])$ ]]; then
    return 1
  fi
  local value="${BASH_REMATCH[1]}"
  local unit="${BASH_REMATCH[2]}"
  case "${unit}" in
    s) echo "${value}" ;;
    m) echo $((value * 60)) ;;
    h) echo $((value * 3600)) ;;
    d) echo $((value * 86400)) ;;
    *) return 1 ;;
  esac
}

testkit_runtime_iso_to_epoch() {
  local raw="${1:-}"
  if date -d "${raw}" +%s >/dev/null 2>&1; then
    date -d "${raw}" +%s
    return 0
  fi

  # BSD/macOS date fallback: Docker timestamps are UTC and may contain fractions.
  local trimmed="${raw%%.*}"
  trimmed="${trimmed%Z}"
  if date -j -u -f '%Y-%m-%dT%H:%M:%S' "${trimmed}" +%s >/dev/null 2>&1; then
    date -j -u -f '%Y-%m-%dT%H:%M:%S' "${trimmed}" +%s
    return 0
  fi

  return 1
}

testkit_runtime_decision() {
  local age_seconds="$1"
  local active_runs="$2"
  local ttl_seconds="$3"

  if [[ "${active_runs}" -gt 0 ]]; then
    printf 'keep\tACTIVE_RUN\n'
    return 0
  fi
  if [[ "${age_seconds}" -lt "${ttl_seconds}" ]]; then
    printf 'keep\tTTL_NOT_EXPIRED\n'
    return 0
  fi
  printf 'delete\tRUNTIME_TTL_EXPIRED\n'
}

testkit_runtime_json_escape() {
  local value="${1:-}"
  value="${value//\\/\\\\}"
  value="${value//\"/\\\"}"
  value="${value//$'\n'/\\n}"
  printf '%s' "${value}"
}

testkit_runtime_write_audit() {
  local mode="$1"
  local older_than="$2"
  local ttl_seconds="$3"
  local rows_file="$4"
  local json_stdout="$5"

  local root="${PROJECT_ROOT:-${TESTKIT_PROJECT_ROOT:-}}"
  if [[ -z "${root}" ]]; then
    return 0
  fi

  local audit_dir="${root}/.testkit/reports/cleanup"
  mkdir -p "${audit_dir}"
  local stamp
  stamp="$(date -u +%Y%m%dT%H%M%SZ)"
  local timestamp
  timestamp="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  local audit_file="${audit_dir}/runtime_cleanup_${stamp}.json"
  local latest_file="${audit_dir}/runtime_cleanup_latest.json"

  {
    printf '{\n'
    printf '  "version": 1,\n\n'
    printf '  "timestamp": "%s",\n' "${timestamp}"
    printf '  "mode": "%s",\n' "${mode}"
    printf '  "older_than": "%s",\n' "$(testkit_runtime_json_escape "${older_than}")"
    printf '  "ttl_seconds": %s,\n\n' "${ttl_seconds}"
    printf '  "projects": [\n'
    local first=1
    while IFS=$'\t' read -r project age active decision reason deleted; do
      [[ -z "${project}" ]] && continue
      if [[ "${first}" -eq 0 ]]; then
        printf ',\n'
      fi
      first=0
      printf '    {"project":"%s","age_seconds":%s,"active_runs":%s,"decision":"%s","reason":"%s","deleted":%s}' \
        "$(testkit_runtime_json_escape "${project}")" \
        "${age}" \
        "${active}" \
        "${decision}" \
        "${reason}" \
        "${deleted}"
    done < "${rows_file}"
    printf '\n  ]\n}\n'
  } > "${audit_file}"
  cp "${audit_file}" "${latest_file}"

  if [[ "${json_stdout}" -eq 1 ]]; then
    cat "${audit_file}"
  fi
}

testkit_runtime_cleanup_project() {
  local docker_bin="$1"
  local project="$2"

  local ids networks volumes
  ids="$(${docker_bin} ps -aq --filter "label=com.docker.compose.project=${project}")"
  if [[ -n "${ids}" ]]; then
    # shellcheck disable=SC2086
    ${docker_bin} rm -f ${ids} >/dev/null
  fi

  networks="$(${docker_bin} network ls -q --filter "label=com.docker.compose.project=${project}")"
  if [[ -n "${networks}" ]]; then
    # shellcheck disable=SC2086
    ${docker_bin} network rm ${networks} >/dev/null 2>&1 || true
  fi

  volumes="$(${docker_bin} volume ls -q \
    --filter "label=com.docker.compose.project=${project}")"
  if [[ -n "${volumes}" ]]; then
    # shellcheck disable=SC2086
    ${docker_bin} volume rm ${volumes} >/dev/null
  fi
}

testkit_runtime_cleanup() {
  local _env_file="$1"
  shift || true

  local older_than="${TESTKIT_RUNTIME_MAX_AGE:-4h}"
  local apply=0
  local force=0
  local json=0
  local quiet=0
  local arg

  for arg in "$@"; do
    case "${arg}" in
      --older-than=*) older_than="${arg#*=}" ;;
      --dry-run) apply=0 ;;
      --apply) apply=1 ;;
      --force) force=1 ;;
      --json) json=1 ;;
      --quiet) quiet=1 ;;
      --help|-h)
        testkit_runtime_cleanup_usage
        return 0
        ;;
      *)
        echo "cleanup runtime: argumento no reconocido: ${arg}" >&2
        testkit_runtime_cleanup_usage >&2
        return 2
        ;;
    esac
  done

  local ttl_seconds
  ttl_seconds="$(testkit_runtime_age_to_seconds "${older_than}" || true)"
  if [[ -z "${ttl_seconds}" ]]; then
    echo "cleanup runtime: --older-than inválido '${older_than}'. Use Ns, Nm, Nh o Nd." >&2
    return 2
  fi

  if [[ "${apply}" -eq 1 && "${force}" -ne 1 ]]; then
    echo 'cleanup runtime: --apply requiere --force porque se eliminan volúmenes de base de datos.' >&2
    return 2
  fi

  local docker_bin="${TESTKIT_DOCKER_BIN:-docker}"
  if ! command -v "${docker_bin}" >/dev/null 2>&1; then
    echo "cleanup runtime: Docker no disponible: ${docker_bin}" >&2
    return 1
  fi

  local now_epoch="${TESTKIT_RUNTIME_CLEANUP_NOW_EPOCH:-$(date +%s)}"
  local candidate_ids
  candidate_ids="$(${docker_bin} ps -aq \
    --filter 'label=io.testkit.runtime=true' \
    --filter 'label=io.testkit.resource=database')"

  local tmp_dir rows_file projects_file
  tmp_dir="$(mktemp -d "${TMPDIR:-/tmp}/testkit_runtime_cleanup.XXXXXX")"
  rows_file="${tmp_dir}/rows.tsv"
  projects_file="${tmp_dir}/projects.txt"
  trap 'rm -rf "${tmp_dir}"' RETURN
  : > "${rows_file}"
  : > "${projects_file}"

  local id project
  for id in ${candidate_ids}; do
    project="$(${docker_bin} inspect -f '{{ index .Config.Labels "com.docker.compose.project" }}' "${id}" 2>/dev/null || true)"
    [[ -z "${project}" || "${project}" == '<no value>' ]] && continue
    printf '%s\n' "${project}" >> "${projects_file}"
  done

  if [[ -s "${projects_file}" ]]; then
    sort -u "${projects_file}" -o "${projects_file}"
  fi

  local mode='dry_run'
  [[ "${apply}" -eq 1 ]] && mode='apply'

  while IFS= read -r project; do
    [[ -z "${project}" ]] && continue

    local db_ids newest_epoch created created_epoch
    db_ids="$(${docker_bin} ps -aq \
      --filter "label=com.docker.compose.project=${project}" \
      --filter 'label=io.testkit.runtime=true' \
      --filter 'label=io.testkit.resource=database')"
    newest_epoch=0
    for id in ${db_ids}; do
      created="$(${docker_bin} inspect -f '{{.Created}}' "${id}" 2>/dev/null || true)"
      [[ -z "${created}" ]] && continue
      created_epoch="$(testkit_runtime_iso_to_epoch "${created}" || true)"
      [[ -z "${created_epoch}" ]] && continue
      if [[ "${created_epoch}" -gt "${newest_epoch}" ]]; then
        newest_epoch="${created_epoch}"
      fi
    done
    [[ "${newest_epoch}" -eq 0 ]] && continue

    local age_seconds active_ids active_runs decision reason deleted
    age_seconds=$((now_epoch - newest_epoch))
    [[ "${age_seconds}" -lt 0 ]] && age_seconds=0
    active_ids="$(${docker_bin} ps -q \
      --filter "label=com.docker.compose.project=${project}" \
      --filter 'label=com.docker.compose.oneoff=True')"
    if [[ -z "${active_ids}" ]]; then
      active_runs=0
    else
      active_runs="$(printf '%s\n' "${active_ids}" | sed '/^$/d' | wc -l | tr -d ' ')"
    fi

    IFS=$'\t' read -r decision reason < <(testkit_runtime_decision "${age_seconds}" "${active_runs}" "${ttl_seconds}")
    deleted=false
    if [[ "${decision}" == 'delete' && "${apply}" -eq 1 ]]; then
      testkit_runtime_cleanup_project "${docker_bin}" "${project}"
      deleted=true
    fi

    printf '%s\t%s\t%s\t%s\t%s\t%s\n' \
      "${project}" "${age_seconds}" "${active_runs}" "${decision}" "${reason}" "${deleted}" >> "${rows_file}"

    if [[ "${json}" -eq 0 && "${quiet}" -eq 0 ]]; then
      if [[ "${decision}" == 'delete' && "${apply}" -eq 0 ]]; then
        printf 'CANDIDATE project=%s age=%ss reason=%s\n' "${project}" "${age_seconds}" "${reason}"
      elif [[ "${decision}" == 'delete' ]]; then
        printf 'DELETE project=%s age=%ss reason=%s\n' "${project}" "${age_seconds}" "${reason}"
      else
        printf 'KEEP project=%s age=%ss active_runs=%s reason=%s\n' "${project}" "${age_seconds}" "${active_runs}" "${reason}"
      fi
    fi
  done < "${projects_file}"

  testkit_runtime_write_audit "${mode}" "${older_than}" "${ttl_seconds}" "${rows_file}" "${json}"

  if [[ "${json}" -eq 0 && "${quiet}" -eq 0 && ! -s "${projects_file}" ]]; then
    echo 'OK cleanup runtime: no TestKit-labeled database runtimes found.'
  fi
}

testkit_runtime_auto_cleanup_if_enabled() {
  local env_file="$1"
  local command_name="$2"

  [[ "${TESTKIT_RUNTIME_AUTO_CLEANUP:-0}" == '1' ]] || return 0
  case "${command_name}" in
    up|run)
      testkit_runtime_cleanup \
        "${env_file}" \
        "--older-than=${TESTKIT_RUNTIME_MAX_AGE:-4h}" \
        --apply \
        --force \
        --quiet
      ;;
    *) return 0 ;;
  esac
}
