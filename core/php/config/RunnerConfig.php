<?php
declare(strict_types=1);

namespace Testkit\Core\Config;

use Testkit\Core\Common\Env;
use Testkit\Core\Common\Paths;

final class RunnerConfig
{
    /**
     * @return array<string,mixed>
     */
    public static function forSuite(string $suiteId, string $testsDir, string $defaultCoverageDir, string $language): array
    {
        $scope = strtolower(Env::string('TEST_SCOPE', defined('TEST_SCOPE_DEFAULT') ? (string)TEST_SCOPE_DEFAULT : 'all'));
        $category = strtolower(Env::string('TEST_CATEGORY', 'all'));
        if ($category === '') {
            $category = 'all';
        }

        $coverageRoot = Env::string('TEST_COVERAGE_DIR', '');
        if ($coverageRoot !== '') {
            $coverageDir = Paths::normalize($coverageRoot . '/' . $suiteId);
        } else {
            $coverageDir = $defaultCoverageDir;
        }

        $thresholds = [
            'slow_ms' => max(1, Env::int('TEST_SLOW_THRESHOLD_MS', 1500)),
            'slow_top' => max(1, Env::int('TEST_SLOW_TOP', 10)),
            'perf_max_ms' => max(0, Env::int('TEST_PERF_MAX_MS', 0)),
            'perf_warn_ms' => max(0, Env::int('TEST_PERF_WARN_MS', 0)),
            'flake_window' => max(5, Env::int('TEST_FLAKE_WINDOW', 20)),
        ];

        $moduleLevel = max(1, Env::int('TK_MODULE_LEVEL', 2));
        $tagMap = Env::string('TK_TAG_MAP', '');

        return [
            'suite_id' => $suiteId,
            'language' => $language,
            'tests_dir' => Paths::normalize($testsDir),
            'scope' => $scope,
            'category' => $category,
            'match' => Env::string('TEST_MATCH', ''),
            'list_only' => Env::bool('TEST_LIST', false),
            'fail_fast' => Env::bool('TEST_FAIL_FAST', true),
            'jobs' => max(1, Env::int('TEST_JOBS', 1)),
            'require_tests' => Env::bool('TEST_REQUIRE_TESTS', false),
            'coverage' => Env::bool('TEST_COVERAGE', false),
            'coverage_format' => strtolower(Env::string('TEST_COVERAGE_FORMAT', 'lcov')),
            'coverage_dir' => $coverageDir,
            'thresholds' => $thresholds,
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'python_binary' => Env::string('TEST_PYTHON_BINARY', 'python3'),
            'node_binary' => Env::string('NODE_BINARY', 'node'),
            'js_require_node' => Env::bool('TEST_JS_REQUIRE_NODE', false),
            'metadata_lines' => max(10, Env::int('TEST_METADATA_SCAN_LINES', 60)),
            'tags_from_filename' => Env::bool('TEST_TAGS_FROM_FILENAME', true),
            'module_level' => $moduleLevel,
            'tag_map' => $tagMap,
            'critical_tags' => Env::csv('TEST_CRITICAL_TAGS', 'critical,contract'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public static function meta(): array
    {
        return [
            'meta_fail_fast' => Env::bool('TEST_META_FAIL_FAST', false),
            'child_fail_fast' => Env::bool('TEST_CHILD_FAIL_FAST', false),
            'target' => strtolower(Env::string('TEST_TARGET', 'all')),
            'testkit_root' => Paths::testkitRoot(),
            'repo_root' => Paths::repoRoot(),
            'reports_root' => Paths::reportsRoot(),
        ];
    }
}
