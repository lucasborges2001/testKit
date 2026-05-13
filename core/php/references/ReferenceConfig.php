<?php
declare(strict_types=1);

namespace Testkit\Core\References;

use Testkit\Core\Common\Env;

final class ReferenceConfig
{
    /** @var array<int,string> */
    public array $ignoreDirs;

    public function __construct(
        public string $scope,
        public string $explicitRoot,
        public int $timeoutSec,
        public int $maxFiles,
        public int $maxBytesPerFile,
        public int $maxViolations,
        public string $dynamicSeverity,
        array $ignoreDirs
    ) {
        $this->ignoreDirs = array_values(array_filter(array_map(
            static fn(string $dir): string => trim(str_replace('\\', '/', $dir), '/'),
            $ignoreDirs
        ), static fn(string $dir): bool => $dir !== ''));
    }

    public static function fromEnv(): self
    {
        $scope = strtolower(trim(Env::string('TESTKIT_REFERENCE_SCOPE', 'back')));
        if (!in_array($scope, ['back', 'front'], true)) {
            $scope = 'back';
        }

        $dynamicSeverity = strtolower(trim(Env::string('TESTKIT_REFERENCE_DYNAMIC_SEVERITY', 'warn')));
        if (!in_array($dynamicSeverity, ['ignore', 'warn', 'error'], true)) {
            $dynamicSeverity = 'warn';
        }

        return new self(
            scope: $scope,
            explicitRoot: Env::string('TESTKIT_REFERENCE_ROOT', ''),
            timeoutSec: max(0, Env::int('TESTKIT_REFERENCE_TIMEOUT_SEC', 20)),
            maxFiles: max(0, Env::int('TESTKIT_REFERENCE_MAX_FILES', 3000)),
            maxBytesPerFile: max(1, Env::int('TESTKIT_REFERENCE_MAX_BYTES_PER_FILE', 1048576)),
            maxViolations: max(1, Env::int('TESTKIT_REFERENCE_MAX_VIOLATIONS', 200)),
            dynamicSeverity: $dynamicSeverity,
            ignoreDirs: Env::csv('TESTKIT_REFERENCE_IGNORE_DIRS', 'vendor,node_modules,.git,.testkit,testkit/_out,_out')
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'scope' => $this->scope,
            'explicit_root' => $this->explicitRoot,
            'timeout_sec' => $this->timeoutSec,
            'max_files' => $this->maxFiles,
            'max_bytes_per_file' => $this->maxBytesPerFile,
            'max_violations' => $this->maxViolations,
            'dynamic_severity' => $this->dynamicSeverity,
            'ignore_dirs' => $this->ignoreDirs,
        ];
    }
}
