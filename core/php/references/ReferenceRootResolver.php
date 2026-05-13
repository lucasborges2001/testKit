<?php
declare(strict_types=1);

namespace Testkit\Core\References;

use Testkit\Core\Common\Env;
use Testkit\Core\Common\Paths;

final class ReferenceRootResolver
{
    /**
     * @return array{scope:string,root_input:string,absolute_root:string,relative_root:string,source:string,explicit_absolute:bool}
     */
    public static function resolve(string $repoRoot, ReferenceConfig $config): array
    {
        $repoRoot = self::canonicalExistingDir($repoRoot);
        $source = 'scope:' . $config->scope;
        $rootInput = trim($config->explicitRoot);

        if ($rootInput !== '') {
            $source = 'TESTKIT_REFERENCE_ROOT';
        } elseif ($config->scope === 'front') {
            $front = Env::string('TK_FRONT_DIR', '');
            $public = Env::string('TK_PUBLIC_DIR', '');
            $rootInput = $front !== '' ? $front : $public;
            $source = $front !== '' ? 'TK_FRONT_DIR' : 'TK_PUBLIC_DIR';
        } else {
            $rootInput = Env::string('TK_BACK_DIR', '');
            $source = 'TK_BACK_DIR';
        }

        if ($rootInput === '') {
            throw self::error(
                'reference_root_missing',
                'No se pudo resolver reference root: ' . $source . ' no está definido. scope=' . $config->scope . ' source=' . $source
            );
        }

        $explicitAbsolute = self::isAbsolute($rootInput);
        $candidate = $explicitAbsolute
            ? Paths::normalize($rootInput)
            : self::normalizeLexical($repoRoot . '/' . ltrim(str_replace('\\', '/', $rootInput), '/'));

        if (!self::isInside($candidate, $repoRoot)) {
            throw self::error(
                'reference_root_outside_repo',
                'Reference root queda fuera del repo: ' . $rootInput
            );
        }

        if (is_file($candidate)) {
            throw self::error(
                'reference_root_not_directory',
                'Reference root apunta a archivo, no a directorio: ' . $rootInput
            );
        }

        if (!is_dir($candidate)) {
            throw self::error(
                'reference_root_missing',
                'Reference root no existe: ' . $rootInput
            );
        }

        $absolute = self::canonicalExistingDir($candidate);
        if (!self::isInside($absolute, $repoRoot)) {
            throw self::error(
                'reference_root_outside_repo',
                'Reference root queda fuera del repo después de resolver symlinks: ' . $rootInput
            );
        }

        return [
            'scope' => $config->scope,
            'root_input' => $rootInput,
            'absolute_root' => $absolute,
            'relative_root' => $absolute === $repoRoot ? '.' : Paths::relativeToRepo($absolute),
            'source' => $source,
            'explicit_absolute' => $explicitAbsolute,
        ];
    }

    public static function causeCodeFor(\Throwable $error): string
    {
        if ($error instanceof ReferenceConfigException) {
            return $error->causeCode;
        }

        $message = $error->getMessage();
        foreach ([
            'reference_invalid_scope',
            'reference_invalid_dynamic_severity',
            'reference_invalid_regex',
            'reference_root_missing',
            'reference_root_outside_repo',
            'reference_root_not_directory',
            'reference_invalid_limit',
        ] as $causeCode) {
            if (str_contains($message, $causeCode)) {
                return $causeCode;
            }
        }

        return 'reference_invalid_config';
    }

    private static function error(string $causeCode, string $message): ReferenceConfigException
    {
        return new ReferenceConfigException($causeCode, $message);
    }

    private static function canonicalExistingDir(string $dir): string
    {
        $real = realpath($dir);
        if (is_string($real) && $real !== '') {
            return Paths::normalize($real);
        }

        return self::normalizeLexical($dir);
    }

    private static function normalizeLexical(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $prefix = '';
        if (preg_match('/^[A-Za-z]:\//', $path) === 1) {
            $prefix = substr($path, 0, 2);
            $path = substr($path, 2);
        }

        $absolute = str_starts_with($path, '/');
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if ($parts !== [] && end($parts) !== '..') {
                    array_pop($parts);
                    continue;
                }
                if (!$absolute) {
                    $parts[] = '..';
                }
                continue;
            }
            $parts[] = $part;
        }

        $normalized = ($absolute ? '/' : '') . implode('/', $parts);
        if ($prefix !== '') {
            $normalized = $prefix . $normalized;
        }
        return rtrim($normalized, '/') ?: ($absolute ? '/' : '.');
    }

    private static function isAbsolute(string $path): bool
    {
        $path = trim($path);
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    private static function isInside(string $path, string $root): bool
    {
        $path = Paths::normalize($path);
        $root = rtrim(Paths::normalize($root), '/');
        return $path === $root || str_starts_with($path, $root . '/');
    }
}
