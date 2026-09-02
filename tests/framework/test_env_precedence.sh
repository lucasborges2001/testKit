#!/usr/bin/env bash
set -euo pipefail

source "$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)/lib/bash/env.sh"

env_file="$(mktemp)"
trap 'rm -f "$env_file"' EXIT
unset TESTKIT_STACK TEST_STORE_DRIVER TEST_STORE_PROVISION COMPOSE_PROJECT_NAME COMPOSE_FILE TESTKIT_ALLOW_ENV_OVERRIDES TESTKIT_MODE || true
printf 'TESTKIT_OVERRIDE=file\nTESTKIT_FILE_ONLY=file\n' > "$env_file"

export TESTKIT_OVERRIDE=process
unset TESTKIT_FILE_ONLY || true
testkit_load_env_kv_safe "$env_file"

[[ "$TESTKIT_OVERRIDE" == process ]]
[[ "$TESTKIT_FILE_ONLY" == file ]]

[[ "$(testkit_env_file_declares TESTKIT_OVERRIDE && echo yes)" == yes ]]
[[ "$(testkit_env_infrastructure_override_conflicts)" == "" ]]

printf 'TEST_STORE_DRIVER=none\nTESTKIT_STACK=\n' > "$env_file"
export TEST_STORE_DRIVER=mysql
export TESTKIT_STACK=mysql
testkit_load_env_kv_safe "$env_file"
conflicts="$(testkit_env_infrastructure_override_conflicts)"
[[ "$conflicts" == *"TEST_STORE_DRIVER|"* ]]
[[ "$conflicts" == *"TESTKIT_STACK|"* ]]

set +e
TESTKIT_MODE=agent TESTKIT_ALLOW_ENV_OVERRIDES=0 testkit_validate_env_overrides >/tmp/testkit-env-precedence.out 2>/tmp/testkit-env-precedence.err
rc=$?
set -e
[[ "$rc" -eq 1 ]]

TESTKIT_MODE=agent TESTKIT_ALLOW_ENV_OVERRIDES=1 testkit_validate_env_overrides

printf 'TEST_STORE_DRIVER=none\n' > "$env_file"
unset TESTKIT_STACK TEST_STORE_DRIVER || true
testkit_load_env_kv_safe "$env_file"
export TESTKIT_STACK=mysql
conflicts="$(testkit_env_infrastructure_override_conflicts)"
[[ "$conflicts" == *"TESTKIT_STACK|process|driver_none|mysql|"* ]]
