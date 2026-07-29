<?php
declare(strict_types=1);

namespace Testkit\Core\Config;

use InvalidArgumentException;

final class RunRequest
{
    /** @param list<string> $tests */
    private function __construct(
        public readonly string $selectorKind,
        public readonly string $selectorName,
        public readonly bool $listOnly,
        public readonly array $tests,
        public readonly string $selectionFile,
        public readonly bool $help
    ) {
    }

    /** @param array<int,string> $argv */
    public static function parse(array $argv): self
    {
        $args = array_values(array_slice($argv, 1));
        $selectorKind = '';
        $selectorName = '';
        $listOnly = false;
        $tests = [];
        $selectionFile = '';
        $help = false;

        for ($i = 0, $count = count($args); $i < $count; $i++) {
            $arg = trim((string)$args[$i]);
            if ($arg === '') {
                throw new InvalidArgumentException('argumento vacío no soportado');
            }

            if (in_array($arg, ['--help', '-h'], true)) {
                $help = true;
                continue;
            }
            if ($arg === '--list') {
                $listOnly = true;
                continue;
            }

            $matched = false;
            foreach (['suite', 'group', 'category'] as $kind) {
                $flag = '--' . $kind;
                if ($arg === $flag || str_starts_with($arg, $flag . '=')) {
                    if ($selectorKind !== '') {
                        throw new InvalidArgumentException('debe declararse exactamente un selector entre --suite, --group y --category');
                    }
                    $selectorKind = $kind;
                    $selectorName = self::valueFor($args, $i, $arg, $flag);
                    $matched = true;
                    break;
                }
            }
            if ($matched) {
                continue;
            }

            if ($arg === '--test' || str_starts_with($arg, '--test=')) {
                $tests[] = self::validateRepoRelativePath(self::valueFor($args, $i, $arg, '--test'), '--test');
                continue;
            }
            if ($arg === '--selection-file' || str_starts_with($arg, '--selection-file=')) {
                if ($selectionFile !== '') {
                    throw new InvalidArgumentException('--selection-file no puede repetirse');
                }
                $selectionFile = self::validateRepoRelativePath(
                    self::valueFor($args, $i, $arg, '--selection-file'),
                    '--selection-file'
                );
                continue;
            }

            throw new InvalidArgumentException(
                "argumento no soportado '{$arg}'; no se aceptan targets posicionales ni aliases"
            );
        }

        if ($help) {
            return new self('', '', false, [], '', true);
        }
        if ($selectorKind === '' || $selectorName === '') {
            throw new InvalidArgumentException('falta selector: usá exactamente uno de --suite, --group o --category');
        }
        if ($tests !== [] && $selectionFile !== '') {
            throw new InvalidArgumentException('--test y --selection-file son mutuamente excluyentes');
        }
        if (ContractRegistry::definition($selectorKind, $selectorName) === null) {
            throw new InvalidArgumentException(
                "selector no soportado: {$selectorKind}:{$selectorName}; valores: "
                . implode('|', ContractRegistry::selectorNames($selectorKind))
            );
        }

        return new self(
            $selectorKind,
            strtolower($selectorName),
            $listOnly,
            array_values(array_unique($tests)),
            $selectionFile,
            false
        );
    }

    public static function assertNoLegacyTargetEnvironment(): void
    {
        $environment = getenv();
        if (!is_array($environment)) {
            $environment = [];
        }
        foreach ($environment as $key => $value) {
            $key = strtoupper((string)$key);
            if ((string)$value === '') {
                continue;
            }
            if ($key === 'TEST_TARGET' || str_starts_with($key, 'TESTKIT_TARGET_')) {
                throw new InvalidArgumentException(
                    "variable legacy no soportada: {$key}; usá --suite, --group o --category"
                );
            }
        }
    }

    public function applyEnvironment(): void
    {
        self::setEnv('TESTKIT_SELECTOR_KIND', $this->selectorKind);
        self::setEnv('TESTKIT_SELECTOR_NAME', $this->selectorName);
        self::setEnv('TEST_CATEGORY', $this->selectorKind === 'category' ? $this->selectorName : 'all');
        self::setEnv('TEST_LIST', $this->listOnly ? '1' : '0');

        // Puente interno acotado: se elimina junto con los aliases de selección en Fase 3.2.
        if ($this->tests !== []) {
            self::setEnv('TEST_MATCH_LIST', implode(',', $this->tests));
            self::setEnv('TEST_MATCH_LIST_MODE', 'exact');
            self::unsetEnv('TEST_MATCH_FILE');
            self::unsetEnv('TEST_MATCH');
        } elseif ($this->selectionFile !== '') {
            self::setEnv('TEST_MATCH_FILE', $this->selectionFile);
            self::setEnv('TEST_MATCH_LIST_MODE', 'exact');
            self::unsetEnv('TEST_MATCH_LIST');
            self::unsetEnv('TEST_MATCH');
        }
    }

    /** @param list<string> $args */
    private static function valueFor(array $args, int &$index, string $arg, string $flag): string
    {
        if (str_starts_with($arg, $flag . '=')) {
            $value = substr($arg, strlen($flag) + 1);
        } else {
            $index++;
            $value = (string)($args[$index] ?? '');
        }
        $value = strtolower($flag) === '--test' || strtolower($flag) === '--selection-file'
            ? trim($value)
            : strtolower(trim($value));
        if ($value === '') {
            throw new InvalidArgumentException("{$flag} exige un valor no vacío");
        }
        return $value;
    }

    private static function validateRepoRelativePath(string $path, string $flag): string
    {
        $normalized = preg_replace('#/+#', '/', str_replace('\\', '/', trim($path))) ?: '';
        $normalized = preg_replace('#^\./+#', '', $normalized) ?: $normalized;
        $normalized = trim($normalized, '/');
        if ($normalized === '' || str_contains($normalized, "\0")) {
            throw new InvalidArgumentException("{$flag} contiene una ruta inválida");
        }
        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1) {
            throw new InvalidArgumentException("{$flag} exige una ruta repo-relative: {$path}");
        }
        foreach (explode('/', $normalized) as $part) {
            if ($part === '..') {
                throw new InvalidArgumentException("{$flag} no admite path traversal: {$path}");
            }
        }
        return $normalized;
    }

    private static function setEnv(string $key, string $value): void
    {
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    private static function unsetEnv(string $key): void
    {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }
}
