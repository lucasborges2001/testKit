<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting;

final class FailureClassifier
{
    /**
     * @param array<int,array<string,mixed>> $failures
     * @return array<int,array<string,mixed>>
     */
    public static function summarize(array $failures, int $top = 5): array
    {
        $groups = [];

        foreach ($failures as $failure) {
            if (!is_array($failure)) {
                continue;
            }

            $family = self::classify($failure);
            if (!isset($groups[$family])) {
                $groups[$family] = [
                    'family' => $family,
                    'label' => self::label($family),
                    'next_step' => self::nextStep($family),
                    'count' => 0,
                    'examples' => [],
                ];
            }

            $groups[$family]['count']++;
            if (count($groups[$family]['examples']) < 3) {
                $groups[$family]['examples'][] = self::example($failure);
            }
        }

        $rows = array_values($groups);
        usort(
            $rows,
            static function (array $a, array $b): int {
                $byCount = ((int)($b['count'] ?? 0)) <=> ((int)($a['count'] ?? 0));
                if ($byCount !== 0) {
                    return $byCount;
                }

                return strcmp((string)($a['family'] ?? ''), (string)($b['family'] ?? ''));
            }
        );

        return array_slice($rows, 0, max(1, $top));
    }

    /**
     * @param array<string,mixed> $failure
     */
    public static function classify(array $failure): string
    {
        $blob = self::blob($failure);

        if (preg_match('/\b(TEST_DB_DSN vac[ií]o|tk_db_config\(\)|usuario admin MySQL|password admin MySQL|TEST_MYSQL_ADMIN_USER|MYSQL_ROOT_PASSWORD|DB_ENV_PATH)\b/i', $blob) === 1) {
            return 'env_contract';
        }

        if (preg_match('/\b(Unknown column|Base table or view not found|Table .* doesn\'t exist|SQLSTATE\[[0-9A-Z]+\].*(42S02|42S22)|schema_name|information_schema)\b/i', $blob) === 1) {
            return 'schema_drift';
        }

        if (preg_match('/\b(Cannot redeclare function|Call to undefined function|undefined function|Falta runner JS|Falta .*bootstrap|BOOTSTRAP ERROR)\b/i', $blob) === 1) {
            return 'bootstrap_or_symbol';
        }

        if (preg_match('/\b(ModuleNotFoundError|ImportError|No module named)\b/i', $blob) === 1) {
            return 'import_bootstrap';
        }

        if (preg_match('/\b(unexpected exception message|AssertionError|assertion|pvt_contains|pvt_eq|pvt_throws)\b/i', $blob) === 1) {
            return 'assertion_contract';
        }

        return 'app_runtime';
    }

    /**
     * @param array<string,mixed> $failure
     */
    private static function blob(array $failure): string
    {
        $parts = [];
        foreach (['message', 'assertion', 'trace_excerpt', 'stderr_excerpt', 'stdout_excerpt', 'error_type'] as $key) {
            $value = trim((string)($failure[$key] ?? ''));
            if ($value !== '') {
                $parts[] = $value;
            }
        }

        return implode("\n", $parts);
    }

    private static function label(string $family): string
    {
        return match ($family) {
            'env_contract' => 'Contrato/env',
            'schema_drift' => 'Drift de schema',
            'bootstrap_or_symbol' => 'Bootstrap/símbolos',
            'import_bootstrap' => 'Import/bootstrap',
            'assertion_contract' => 'Contrato de assertions',
            default => 'Runtime de aplicación',
        };
    }

    private static function nextStep(string $family): string
    {
        return match ($family) {
            'env_contract' => 'Corregir .env.test/doctor antes de releer fallos funcionales.',
            'schema_drift' => 'Revisar seeds, migraciones y compatibilidad schema <-> tests.',
            'bootstrap_or_symbol' => 'Revisar helpers cargados, colisiones de funciones y bootstrap del módulo.',
            'import_bootstrap' => 'Revisar PYTHONPATH/autoload/cwd del proceso hijo antes de diagnosticar lógica de aplicación.',
            'assertion_contract' => 'Alinear mensajes/contratos del test con la API pública real.',
            default => 'Leer el primer stack trace completo y aislar el módulo roto.',
        };
    }

    /**
     * @param array<string,mixed> $failure
     * @return array<string,string>
     */
    private static function example(array $failure): array
    {
        $file = trim((string)($failure['file'] ?? $failure['test_id'] ?? 'unknown'));
        $message = '';

        foreach (['message', 'assertion', 'stderr_excerpt', 'stdout_excerpt', 'trace_excerpt'] as $field) {
            $value = trim((string)($failure[$field] ?? ''));
            if ($value !== '') {
                $message = self::firstLine($value);
                break;
            }
        }

        return [
            'file' => $file,
            'message' => self::truncate($message, 160),
        ];
    }

    private static function firstLine(string $text): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line !== '') {
                return $line;
            }
        }

        return '';
    }

    private static function truncate(string $text, int $limit): string
    {
        if ($limit <= 0) {
            return '';
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (\mb_strlen($text) <= $limit) {
                return $text;
            }

            return rtrim(\mb_substr($text, 0, $limit - 1)) . '…';
        }

        if (strlen($text) <= $limit) {
            return $text;
        }

        return rtrim(substr($text, 0, $limit - 1)) . '…';
    }
}
