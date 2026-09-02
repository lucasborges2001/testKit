#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"

if ! command -v docker >/dev/null 2>&1; then
  echo 'SKIP test_phpmailer_runner_image: docker unavailable'
  exit 0
fi

TAG="testkit-phpmailer-contract:local-$$"
cleanup() {
  docker image rm -f "$TAG" >/dev/null 2>&1 || true
}
trap cleanup EXIT INT TERM

echo '=== BUILD TESTKIT CORE FOR PHPMAILER CONTRACT ==='
docker build --target core -t "$TAG" "$ROOT"

echo '=== VERIFY PHPMAILER AUTOLOAD CONTRACT ==='
docker run --rm "$TAG" php -r '
$autoload = getenv("TESTKIT_PHPMAILER_AUTOLOAD") ?: "/opt/testkit/vendor/autoload.php";
if (!is_file($autoload)) {
    fwrite(STDERR, "FAIL: canonical PHPMailer autoload missing\n");
    exit(10);
}
require_once $autoload;
if (!class_exists("PHPMailer\\PHPMailer\\PHPMailer")) {
    fwrite(STDERR, "FAIL: PHPMailer class unavailable\n");
    exit(11);
}
$compat = "/usr/share/php/PHPMailer/autoload.php";
if (!is_file($compat)) {
    fwrite(STDERR, "FAIL: compatibility PHPMailer autoload missing\n");
    exit(12);
}
require_once $compat;
echo "phpmailer_runner_contract=PASS\n";
'

echo 'PASS test_phpmailer_runner_image'
