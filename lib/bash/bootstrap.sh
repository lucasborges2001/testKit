#!/usr/bin/env bash
set -euo pipefail

TESTKIT_ENTRY_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
# shellcheck source=/dev/null
source "${TESTKIT_ENTRY_DIR}/lib/bash/env.sh"
# shellcheck source=/dev/null
source "${TESTKIT_ENTRY_DIR}/lib/bash/stack.sh"
# shellcheck source=/dev/null
source "${TESTKIT_ENTRY_DIR}/lib/bash/rewrite.sh"
# shellcheck source=/dev/null
source "${TESTKIT_ENTRY_DIR}/lib/bash/doctor.sh"
# shellcheck source=/dev/null
source "${TESTKIT_ENTRY_DIR}/lib/bash/controller.sh"
