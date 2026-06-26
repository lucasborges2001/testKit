<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting\Console;

final class ConsoleRenderLimits
{
    public const MAX_FAILURES = 8;
    public const MAX_FAILURE_LINES = 4;
    public const MAX_SLOW_TESTS = 5;
    public const MAX_FRAGILITY = 5;
    public const MAX_TRIAGE_GROUPS = 4;
    public const MAX_TRIAGE_EXAMPLES = 1;
    public const MAX_PERF_VIOLATIONS = 5;
    public const MAX_ACTIONS = 4;
    public const MAX_REGRESSION_ITEMS = 3;
    public const MAX_PROGRESS_CURRENT_LEN = 28;
    public const MAX_PROGRESS_WORKER_PATH_LEN = 18;
    public const MAX_PROGRESS_WORKERS_LEN = 84;
    public const MAX_WARNING_REL_LEN = 40;
    public const MAX_TEST_REL_LEN = 28;

    private function __construct()
    {
    }
}
