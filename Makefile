SHELL := /bin/bash

help:
	@echo "Targets: doctor up up-pg down seed test test-back test-back-python test-front test-smoke test-perf test-stress cov-back cov-front report query-report self-test"

doctor:
	./bin/testkit doctor

up:
	./bin/testkit up -d

up-pg:
	./bin/testkit --pg up -d

down:
	./bin/testkit down -v

seed:
	./scripts/seed.sh

test:
	./bin/testkit run --rm testkit php runTest.php --group all

test-back:
	./bin/testkit run --rm testkit php runTest.php --group back

test-back-python:
	./bin/testkit run --rm testkit php runTest.php --suite back-python

test-front:
	./bin/testkit run --rm testkit php runTest.php --group front

test-smoke:
	./bin/testkit run --rm testkit php runTest.php --category smoke

test-perf:
	./bin/testkit run --rm testkit php runTest.php --category perf

test-stress:
	./bin/testkit run --rm testkit php runTest.php --category stress

cov-back:
	./bin/testkit run --rm -e TEST_COVERAGE=1 -e TEST_COVERAGE_FORMAT=both testkit php runTest.php --suite back-php

cov-front:
	./bin/testkit run --rm -e TEST_COVERAGE=1 -e TEST_COVERAGE_FORMAT=both testkit php runTest.php --suite front-php

report:
	./bin/testkit run --rm testkit php /workspace/testkit/scripts/report.php

query-report:
	./bin/testkit run --rm testkit php /workspace/testkit/scripts/query_report.php

self-test:
	php tests/framework/run.php
	bash tests/framework/test_suite_config_risk_policy.sh
	bash tests/framework/test_env_contract.sh
	bash tests/framework/test_mailpit_stack.sh
	bash tests/framework/test_phpmailer_runner_image.sh
	bash tests/framework/test_host_suite_agent.sh
	bash tests/framework/test_remote_host_agent.sh
	php tests/framework/test_remote_host_agent_powershell_contract.php
	php tests/framework/test_remote_host_native_powershell_contract.php
	bash tests/framework/test_suite_config_entrypoint.sh
