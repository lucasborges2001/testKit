#!/usr/bin/env bash
set -euo pipefail

source "$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)/lib/bash/env.sh"

env_file="$(mktemp)"
trap 'rm -f "$env_file"' EXIT
printf 'TESTKIT_OVERRIDE=file\nTESTKIT_FILE_ONLY=file\n' > "$env_file"

export TESTKIT_OVERRIDE=process
unset TESTKIT_FILE_ONLY || true
testkit_load_env_kv_safe "$env_file"

[[ "$TESTKIT_OVERRIDE" == process ]]
[[ "$TESTKIT_FILE_ONLY" == file ]]
