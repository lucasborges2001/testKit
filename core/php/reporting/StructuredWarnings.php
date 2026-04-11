<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting;

final class StructuredWarnings
{
    /**
     * @param mixed $warnings
     * @return array<int,array<string,mixed>>
     */
    public static function canonicalize(mixed $warnings): array
    {
        if (!is_array($warnings)) {
            return [];
        }

        $rows = [];
        foreach ($warnings as $warning) {
            if (is_string($warning)) {
                $rows[] = self::fromText($warning);
                continue;
            }

            if (!is_array($warning)) {
                continue;
            }

            $blocking = (bool)($warning['blocking'] ?? false);
            $severity = self::safeSeverity((string)($warning['severity'] ?? ($blocking ? 'error' : 'warn')));
            $rows[] = [
                'code' => self::safeCode((string)($warning['code'] ?? 'GENERIC_WARNING')),
                'severity' => $severity,
                'classification' => self::safeClassification((string)($warning['classification'] ?? ''), $severity, $blocking),
                'blocking' => $blocking,
                'summary' => trim((string)($warning['summary'] ?? $warning['message'] ?? 'warning')),
                'count' => max(1, (int)($warning['count'] ?? 1)),
                'context' => is_array($warning['context'] ?? null) ? $warning['context'] : [],
            ];
        }

        return array_values($rows);
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public static function fromText(
        string $summary,
        string $code = 'GENERIC_WARNING',
        string $severity = 'warn',
        bool $blocking = false,
        array $context = []
    ): array {
        $severity = self::safeSeverity($severity !== '' ? $severity : ($blocking ? 'error' : 'warn'));
        return [
            'code' => self::safeCode($code),
            'severity' => $severity,
            'classification' => self::safeClassification('', $severity, $blocking),
            'blocking' => $blocking,
            'summary' => trim($summary) !== '' ? trim($summary) : 'warning',
            'count' => 1,
            'context' => $context,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $warnings
     */
    public static function joinSummaries(array $warnings): string
    {
        $parts = [];
        foreach (self::canonicalize($warnings) as $warning) {
            $parts[] = (string)($warning['summary'] ?? 'warning');
        }

        return implode(' ', $parts);
    }

    private static function safeCode(string $code): string
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return 'GENERIC_WARNING';
        }

        $code = preg_replace('/[^A-Z0-9_]+/', '_', $code) ?: 'GENERIC_WARNING';
        return trim($code, '_') !== '' ? trim($code, '_') : 'GENERIC_WARNING';
    }

    private static function safeSeverity(string $severity): string
    {
        $severity = strtolower(trim($severity));
        return in_array($severity, ['info', 'warn', 'warning', 'error'], true)
            ? ($severity === 'warning' ? 'warn' : $severity)
            : 'warn';
    }

    private static function safeClassification(string $classification, string $severity, bool $blocking): string
    {
        $classification = strtolower(trim($classification));
        if (in_array($classification, ['operational', 'configuration', 'concurrency', 'blocking'], true)) {
            return $classification;
        }

        if ($blocking || $severity === 'error') {
            return 'blocking';
        }

        return 'operational';
    }
}
