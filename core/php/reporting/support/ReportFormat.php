<?php
declare(strict_types=1);

/**
 * ============================================================================
 * @file    testkit/core/php/reporting/support/ReportFormat.php
 * @brief   Normaliza y valida los formatos de salida soportados por report.php.
 * ============================================================================
 */

namespace Base\TestKit\Reporting\Support;

final class ReportFormat
{
    public const TEXT = 'text';
    public const JSON = 'json';
    public const HTML = 'html';

    /**
     * @return list<string>
     */
    public static function supported(): array
    {
        return [self::TEXT, self::JSON, self::HTML];
    }

    public static function normalize(?string $format): string
    {
        $normalized = strtolower(trim((string) ($format ?: self::TEXT)));

        if ($normalized === 'md' || $normalized === 'markdown') {
            return self::TEXT;
        }

        if (!in_array($normalized, self::supported(), true)) {
            throw new \InvalidArgumentException(
                sprintf('Formato de reporte no soportado: %s. Soportados: %s', $normalized, implode(', ', self::supported()))
            );
        }

        return $normalized;
    }
}
