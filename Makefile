SHELL := /bin/bash

help:
	@echo "Targets: doctor up up-pg down seed test test-back test-front cov-back cov-front query-report"

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

test-front:
	./bin/testkit run --rm testkit php runTest.php front

cov-back:
	./bin/testkit run --rm -e TEST_COVERAGE=1 -e TEST_COVERAGE_FORMAT=lcov testkit php runTest.php back

cov-front:
	./bin/testkit run --rm -e TEST_COVERAGE=1 -e TEST_COVERAGE_FORMAT=lcov testkit php runTest.php front-php

query-report:
	./bin/testkit run --rm testkit php test/scripts/query_report.php
