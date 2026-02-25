#!/usr/bin/env bash
set -euo pipefail

# =============================================================================
# test/scripts/unix/_lib.sh
# Helpers compartidos para scripts de testing (Linux/Mac).
# =============================================================================

log(){ echo "$*"; }
warn(){ echo "[WARN] $*"; }
fail(){ echo "ERROR: $*" >&2; exit 1; }

# -----------------------------------------------------------------------------
# 1) Paths base
# -----------------------------------------------------------------------------
# Se asume que el caller está en test/scripts/unix/*.sh
get_paths() {
  local script_dir test_root repo_root
  script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"      # .../test/scripts/unix
  test_root="$(cd "$script_dir/../.." && pwd)"                     # .../test
  repo_root="$(cd "$test_root/.." && pwd)"                         # .../<repo>
  echo "$script_dir|$test_root|$repo_root"
}

# -----------------------------------------------------------------------------
# 2) Env loader (KEY=VALUE)
# -----------------------------------------------------------------------------
pick_env_file() {
  local test_root="$1" repo_root="$2"
  if [[ -n "${TESTKIT_ENV_FILE:-}" && -f "${TESTKIT_ENV_FILE}" ]]; then
    echo "${TESTKIT_ENV_FILE}"; return 0
  fi
  [[ -f "$test_root/.env.test" ]] && { echo "$test_root/.env.test"; return 0; }
  [[ -f "$repo_root/.env.test" ]] && { echo "$repo_root/.env.test"; return 0; }
  return 1
}

load_env_kv_safe() {
  local f="$1" line
  [[ -f "$f" ]] || return 0
  while IFS= read -r line; do
    [[ "$line" =~ ^[[:space:]]*# ]] && continue
    [[ "$line" =~ ^[[:space:]]*$ ]] && continue
    if [[ "$line" =~ ^[A-Za-z_][A-Za-z0-9_]*= ]]; then
      export "$line"
    fi
  done < "$f"
}

# -----------------------------------------------------------------------------
# 3) Mode / ResetMode
# -----------------------------------------------------------------------------
resolve_mode() {
  local requested="${1:-auto}" repo_root="$2" test_root="$3" m
  m="${requested,,}"

  if [[ "$m" == "auto" ]]; then
    if [[ -n "${TEST_DB_MODE:-}" ]]; then
      m="${TEST_DB_MODE,,}"
    else
      if [[ -x "$repo_root/bin/testkit" || -f "$repo_root/bin/testkit.ps1" || -x "$test_root/bin/testkit" || -f "$test_root/bin/testkit.ps1" ]]; then
        m="docker"
      else
        m="local"
      fi
    fi
  fi

  [[ "$m" == "local" || "$m" == "docker" ]] || fail "TEST_DB_MODE inválido: $m (local|docker|auto)"
  echo "$m"
}

resolve_reset_mode() {
  local requested="${1:-}" r
  r="${requested,,}"
  [[ -z "$r" && -n "${TEST_RESET_MODE:-}" ]] && r="${TEST_RESET_MODE,,}"
  [[ -z "$r" ]] && r="dropdb"
  [[ "$r" == "heavy" || "$r" == "dropdb" || "$r" == "fast" ]] || fail "TEST_RESET_MODE inválido: $r (heavy|dropdb|fast)"
  echo "$r"
}

# -----------------------------------------------------------------------------
# 4) TestKit resolver
# -----------------------------------------------------------------------------
find_testkit() {
  local test_root="$1" repo_root="$2"
  local override="${TESTKIT_BIN:-}"

  if [[ -n "$override" && -x "$override" ]]; then echo "$override"; return 0; fi
  if [[ -x "$test_root/bin/testkit" ]]; then echo "$test_root/bin/testkit"; return 0; fi
  if [[ -x "$repo_root/bin/testkit" ]]; then echo "$repo_root/bin/testkit"; return 0; fi

  fail "No se encontró TestKit. Seteá TESTKIT_BIN o agregá bin/testkit."
}

# -----------------------------------------------------------------------------
# 5) DB Strategy helpers
# -----------------------------------------------------------------------------
get_db_strategy(){ echo "${TEST_DB_STRATEGY:-shared}"; }
get_jobs(){ local j="${TEST_JOBS:-1}"; [[ "$j" =~ ^[0-9]+$ ]] || j=1; (( j < 1 )) && j=1; echo "$j"; }
get_base_db(){ echo "${TEST_MYSQL_DB:-app_test}"; }
get_suffix_fmt(){ echo "${TEST_DB_WORKER_SUFFIX_FORMAT:-_w%02d}"; }

mk_db_name(){
  local base="$1" fmt="$2" w="$3"
  if [[ "$fmt" =~ %0([0-9]+)d ]]; then
    local width="${BASH_REMATCH[1]}"
    printf "%s_w%0*d" "$base" "$width" "$w"
  else
    printf "%s_w%d" "$base" "$w"
  fi
}
