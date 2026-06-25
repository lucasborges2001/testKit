<?php
declare(strict_types=1);

namespace Testkit\Core\Suites;

use Testkit\Core\Common\Paths;
use Testkit\Core\Discovery\TestSelection;

final class SuiteSelection
{
    /**
     * Return the single functional module scope ("back/auth") if all tests share one, else "".
     *
     * @param array<int,array<string,mixed>> $tests
     */
    public static function moduleScope(array $tests): string
    {
        if (empty($tests)) {
            return '';
        }

        $modules = [];
        foreach ($tests as $test) {
            $module = Paths::extractFunctionalModule((string)($test['rel'] ?? ''));
            if ($module === null) {
                return '';
            }
            $modules[$module] = true;
        }

        return count($modules) === 1 ? (string)array_key_first($modules) : '';
    }

    /**
     * Longest common directory prefix of the selected test rel-paths.
     *
     * @param array<int,array<string,mixed>> $tests
     */
    public static function commonDir(array $tests): string
    {
        if (empty($tests)) {
            return '';
        }

        $dirs = array_unique(array_map(
            static fn(array $test): string => dirname(str_replace('\\', '/', (string)($test['rel'] ?? ''))),
            $tests
        ));

        if (count($dirs) === 1) {
            return reset($dirs) ?: '';
        }

        $parts = array_map(
            static fn(string $dir): array => array_values(array_filter(explode('/', $dir), static fn(string $part): bool => $part !== '')),
            array_values($dirs)
        );

        $minLen = min(array_map('count', $parts));
        $common = [];
        for ($i = 0; $i < $minLen; $i++) {
            $seg = $parts[0][$i];
            foreach ($parts as $pathParts) {
                if ($pathParts[$i] !== $seg) {
                    break 2;
                }
            }
            $common[] = $seg;
        }

        return implode('/', $common);
    }

    /**
     * @param array<int,array<string,mixed>> $tests
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    public static function manifest(array $tests, array $config, string $source = 'suite_orchestrator'): array
    {
        $selectedFiles = array_values(array_map(
            static fn(array $test): string => (string)($test['rel'] ?? ''),
            $tests
        ));
        $selectionMetadata = TestSelection::fromConfig($config)->metadata($selectedFiles);

        return array_merge([
            'suite_id' => (string)($config['suite_id'] ?? ''),
            'scope' => (string)($config['scope'] ?? 'all'),
            'category' => (string)($config['category'] ?? 'all'),
            'match' => (string)($config['match'] ?? ''),
            'match_list' => (string)($config['match_list'] ?? ''),
            'match_file' => (string)($config['match_file'] ?? ''),
            'list_only' => (bool)($config['list_only'] ?? false),
            'selected_test_count' => count($tests),
            'selected_module_scope' => self::moduleScope($tests),
            'selected_common_dir' => self::commonDir($tests),
            'source' => $source,
        ], $selectionMetadata);
    }

    /**
     * @param array<string,mixed> $result
     * @param array<int,array<string,mixed>> $tests
     * @param array<string,mixed> $config
     */
    public static function suiteStatus(array $result, array $tests, array $config): string
    {
        if ((bool)($config['list_only'] ?? false)) {
            return 'listed';
        }

        if ($tests === []) {
            return 'no_tests';
        }

        $fail = (int)($result['fail'] ?? 0);
        $pass = (int)($result['pass'] ?? 0);
        $skip = (int)($result['skip'] ?? 0);

        if ($fail > 0) {
            return 'failed';
        }

        if ($pass === 0 && $skip > 0) {
            return 'all_skipped';
        }

        return 'passed';
    }

    /**
     * @param array<string,mixed> $result
     * @param array<string,mixed> $config
     */
    public static function noTestsReason(array $result, array $config): ?string
    {
        if ((string)($result['suite_status'] ?? '') !== 'no_tests') {
            return null;
        }

        $scope = (string)($config['scope'] ?? 'all');
        $category = (string)($config['category'] ?? 'all');
        $selection = TestSelection::fromConfig($config)->metadata([]);
        $source = (string)($selection['selection_source'] ?? 'none');

        $message = "no tests matched the current filters (scope={$scope}, category={$category}, selection_source={$source}";
        if (trim((string)($config['match'] ?? '')) !== '') {
            $message .= ', match=' . trim((string)$config['match']);
        }
        if (trim((string)($config['match_list'] ?? '')) !== '') {
            $message .= ', match_list_entries=' . (int)($selection['selection_entries_count'] ?? 0);
        }
        if (trim((string)($config['match_file'] ?? '')) !== '') {
            $message .= ', match_file=' . trim((string)$config['match_file']);
        }
        $message .= ')';

        return $message;
    }
}
