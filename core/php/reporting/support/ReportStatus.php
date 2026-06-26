<?php
declare(strict_types=1);

/**
 * ============================================================================
 * @file    testkit/core/php/reporting/support/ReportStatus.php
 * @brief   Centraliza la semántica de estados usada por los reportes TestKit.
 * ============================================================================
 */

namespace Base\TestKit\Reporting\Support;

final class ReportStatus
{
    public const OK = 'ok';
    public const REQUIRES_REVIEW = 'requires_review';
    public const FAILED = 'failed';
    public const SKIPPED = 'skipped';
    public const UNAVAILABLE = 'unavailable';
    public const UNKNOWN = 'unknown';

    /**
     * @return list<string>
     */
    public static function supported(): array
    {
        return [
            self::OK,
            self::REQUIRES_REVIEW,
            self::FAILED,
            self::SKIPPED,
            self::UNAVAILABLE,
            self::UNKNOWN,
        ];
    }

    public static function normalize(?string $status): string
    {
        $value = strtolower(trim((string) $status));
        $value = str_replace([' ', '-'], '_', $value);

        return match ($value) {
            'pass', 'passed', 'success', 'successful', 'green', self::OK => self::OK,
            'review', 'requiresreview', 'requires_review', 'warning', 'warnings', 'technical_error' => self::REQUIRES_REVIEW,
            'fail', 'failure', 'failed', 'error', 'errors', 'red' => self::FAILED,
            'skip', 'skipped', 'disabled' => self::SKIPPED,
            'missing', 'not_available', 'n/a', 'unavailable' => self::UNAVAILABLE,
            default => self::UNKNOWN,
        };
    }

    public static function rank(string $status): int
    {
        return match (self::normalize($status)) {
            self::FAILED => 50,
            self::REQUIRES_REVIEW => 40,
            self::UNAVAILABLE => 30,
            self::UNKNOWN => 20,
            self::SKIPPED => 10,
            self::OK => 0,
        };
    }

    /**
     * @param list<string> $statuses
     */
    public static function worst(array $statuses): string
    {
        if ($statuses === []) {
            return self::UNAVAILABLE;
        }

        $worst = self::OK;
        foreach ($statuses as $status) {
            $normalized = self::normalize($status);
            if (self::rank($normalized) > self::rank($worst)) {
                $worst = $normalized;
            }
        }

        return $worst;
    }
}
