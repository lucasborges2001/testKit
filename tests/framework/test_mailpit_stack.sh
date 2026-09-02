#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
export TESTKIT_ROOT_HOST="$ROOT"

# shellcheck source=../../lib/bash/stack.sh
source "$ROOT/lib/bash/stack.sh"

actual="$(testkit_normalize_stack_csv 'smtp,mysql,mailpit')"
[[ "$actual" == 'mailpit,mysql' ]] || {
  echo "FAIL: unexpected normalized stack: $actual" >&2
  exit 1
}

files=()
testkit_resolve_compose_files 'mysql,mailpit' files
joined=" ${files[*]} "
[[ "$joined" == *" compose.mysql.yaml "* ]] || {
  echo 'FAIL: mysql compose file missing' >&2
  exit 1
}
[[ "$joined" == *" compose.mailpit.yaml "* ]] || {
  echo 'FAIL: mailpit compose file missing' >&2
  exit 1
}

grep -q '^  mailpit:$' "$ROOT/compose.mailpit.yaml"
grep -q 'axllent/mailpit:v1.30.0' "$ROOT/compose.mailpit.yaml"
grep -q 'TEST_MAILPIT_SMTP_PORT' "$ROOT/compose.mailpit.yaml"
grep -q 'TEST_MAILPIT_HTTP_PORT' "$ROOT/compose.mailpit.yaml"
grep -q 'libphp-phpmailer' "$ROOT/docker/Dockerfile"
grep -q "'mailpit'" "$ROOT/lib/powershell/Stack.ps1"

echo 'PASS test_mailpit_stack'
