SHELL := /bin/bash

help:
	@echo "Targets: doctor up up-pg down seed test test-back test-back-py test-front test-smoke test-perf test-stress cov-back cov-front report query-report"

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
	./bin/testkit run --rm testkit php runTest.php

test-back:
	./bin/testkit run --rm testkit php runTest.php back

test-back-py:
	./bin/testkit run --rm testkit php runTest.php back-py

test-front:
	./bin/testkit run --rm testkit php runTest.php front

test-smoke:
	./bin/testkit run --rm -e TEST_CATEGORY=smoke testkit php runTest.php smoke

test-perf:
	./bin/testkit run --rm -e TEST_CATEGORY=perf testkit php runTest.php perf

test-stress:
	./bin/testkit run --rm -e TEST_CATEGORY=stress testkit php runTest.php stress

cov-back:
	./bin/testkit run --rm -e TEST_COVERAGE=1 -e TEST_COVERAGE_FORMAT=both testkit php runTest.php back-php

cov-front:
	./bin/testkit run --rm -e TEST_COVERAGE=1 -e TEST_COVERAGE_FORMAT=both testkit php runTest.php front-php

report:
	./bin/testkit run --rm testkit php /workspace/testkit/scripts/report.php

query-report:
	./bin/testkit run --rm testkit php /workspace/testkit/scripts/query_report.php

self-test:
	php tests/framework/run.php
