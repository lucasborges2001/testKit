<?php
declare(strict_types=1);

namespace Testkit\Core\DbProfiling;

final class InstrumentationFinding
{
    /** @param array<string,mixed> $context @return array<string,mixed> */
    public static function make(
        string $code,
        string $severity,
        string $message,
        array $context = [],
        string $recommendation = ''
    ): array {
        $severity = strtolower(trim($severity));
        if (!in_array($severity, ['info', 'watch', 'warn'], true)) {
            $severity = 'watch';
        }

        return [
            'code' => self::sanitizeToken($code, 80),
            'severity' => $severity,
            'message' => InstrumentationContext::sanitizeText($message, 240),
            'context' => InstrumentationContext::sanitizeMap($context),
            'recommendation' => InstrumentationContext::sanitizeText($recommendation, 320),
        ];
    }

    private static function sanitizeToken(string $value, int $max): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9._-]+/', '_', $value) ?? '';
        return substr($value !== '' ? $value : 'unknown', 0, $max);
    }
}
