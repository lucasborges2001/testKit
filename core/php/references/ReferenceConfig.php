<?php
declare(strict_types=1);

namespace Testkit\Core\References;

use Testkit\Core\Common\Env;

final class ReferenceConfig
{
    /** @var array<int,string> */
    public array $ignoreDirs;

    /** @var array<int,string> */
    public array $ignoreRefs;

    /** @var array<int,string> */
    public array $ignoreFiles;

    /** @var array<int,string> */
    public array $ignoreRefRegexes;

    /** @var array<int,string> */
    public array $ignoreFileRegexes;

    public function __construct(
        public string $scope,
        public string $explicitRoot,
        public int $timeoutSec,
        public int $maxFiles,
        public int $maxBytesPerFile,
        public int $maxViolations,
        public string $dynamicSeverity,
        array $ignoreDirs,
        array $ignoreRefs = [],
        array $ignoreFiles = [],
        array $ignoreRefRegexes = [],
        array $ignoreFileRegexes = []
    ) {
        $this->ignoreDirs = self::normalizePathList($ignoreDirs, true);
        $this->ignoreRefs = self::normalizeTextList($ignoreRefs);
        $this->ignoreFiles = self::normalizePathList($ignoreFiles, false);
        $this->ignoreRefRegexes = array_values($ignoreRefRegexes);
        $this->ignoreFileRegexes = array_values($ignoreFileRegexes);
    }

    public static function fromEnv(): self
    {
        $scope = strtolower(trim(self::raw('TESTKIT_REFERENCE_SCOPE', 'back')));
        if (!in_array($scope, ['back', 'front'], true)) {
            throw new ReferenceConfigException(
                'reference_invalid_scope',
                "TESTKIT_REFERENCE_SCOPE inválido: {$scope}. Valores válidos: back|front."
            );
        }

        $dynamicSeverity = strtolower(trim(self::raw('TESTKIT_REFERENCE_DYNAMIC_SEVERITY', 'warn')));
        if (!in_array($dynamicSeverity, ['ignore', 'warn', 'error'], true)) {
            throw new ReferenceConfigException(
                'reference_invalid_dynamic_severity',
                "TESTKIT_REFERENCE_DYNAMIC_SEVERITY inválido: {$dynamicSeverity}. Valores válidos: ignore|warn|error."
            );
        }

        $ignoreRefRegexes = self::regexList('TESTKIT_REFERENCE_IGNORE_REF_REGEX');
        $ignoreFileRegexes = self::regexList('TESTKIT_REFERENCE_IGNORE_FILE_REGEX');

        return new self(
            scope: $scope,
            explicitRoot: Env::string('TESTKIT_REFERENCE_ROOT', ''),
            timeoutSec: self::positiveInt('TESTKIT_REFERENCE_TIMEOUT_SEC', 20),
            maxFiles: self::positiveInt('TESTKIT_REFERENCE_MAX_FILES', 3000),
            maxBytesPerFile: self::positiveInt('TESTKIT_REFERENCE_MAX_BYTES_PER_FILE', 1048576),
            maxViolations: self::positiveInt('TESTKIT_REFERENCE_MAX_VIOLATIONS', 200),
            dynamicSeverity: $dynamicSeverity,
            ignoreDirs: Env::csv('TESTKIT_REFERENCE_IGNORE_DIRS', 'vendor,node_modules,.git,.testkit,testkit/_out,_out'),
            ignoreRefs: Env::csv('TESTKIT_REFERENCE_IGNORE_REFS', ''),
            ignoreFiles: Env::csv('TESTKIT_REFERENCE_IGNORE_FILES', ''),
            ignoreRefRegexes: $ignoreRefRegexes,
            ignoreFileRegexes: $ignoreFileRegexes
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
            'ignore_refs' => $this->ignoreRefs,
            'ignore_ref_regex' => $this->ignoreRefRegexes,
            'ignore_files' => $this->ignoreFiles,
            'ignore_file_regex' => $this->ignoreFileRegexes,
        ];
    }

    private static function raw(string $key, string $default): string
    {
        $value = getenv($key);
        if ($value === false || trim((string)$value) === '') {
            return $default;
        }

        return trim((string)$value);
    }

    private static function positiveInt(string $key, int $default): int
    {
        $raw = getenv($key);
        if ($raw === false || trim((string)$raw) === '') {
            return $default;
        }

        $value = trim((string)$raw);
        if (!preg_match('/^[1-9][0-9]*$/', $value)) {
            throw new ReferenceConfigException(
                'reference_invalid_limit',
                "{$key} inválido: {$value}. Debe ser un entero positivo mayor a cero."
            );
        }

        return (int)$value;
    }

    /**
     * @return array<int,string>
     */
    private static function regexList(string $key): array
    {
        $items = Env::csv($key, '');
        $valid = [];

        foreach ($items as $regex) {
            $regex = trim($regex);
            if ($regex === '') {
                continue;
            }

            set_error_handler(static fn(): bool => true);
            $ok = preg_match($regex, '') !== false;
            restore_error_handler();

            if (!$ok) {
                throw new ReferenceConfigException(
                    'reference_invalid_regex',
                    "{$key} contiene regex inválida: {$regex}"
                );
            }

            $valid[] = $regex;
        }

        return $valid;
    }

    /**
     * @param array<int,string> $values
     * @return array<int,string>
     */
    private static function normalizeTextList(array $values): array
    {
        return array_values(array_filter(array_map(
            static fn(string $value): string => trim(str_replace('\\', '/', $value)),
            $values
        ), static fn(string $value): bool => $value !== ''));
    }

    /**
     * @param array<int,string> $values
     * @return array<int,string>
     */
    private static function normalizePathList(array $values, bool $trimOuterSlashes): array
    {
        return array_values(array_filter(array_map(
            static function (string $value) use ($trimOuterSlashes): string {
                $value = trim(str_replace('\\', '/', $value));
                return $trimOuterSlashes ? trim($value, '/') : ltrim($value, '/');
            },
            $values
        ), static fn(string $value): bool => $value !== ''));
    }
}
