<?php
declare(strict_types=1);

namespace Testkit\Core\Plc;

use InvalidArgumentException;

final class PlcArtifact
{
    /** @var list<string> */
    private const EXECUTION_STATES = ['EXECUTED', 'NOT_EXECUTED'];

    /** @var list<string> */
    private const STATUSES = ['PASS', 'FAIL', 'UNKNOWN', 'UNAVAILABLE'];

    private const REDACTED = '[REDACTED]';

    /**
     * Build a bounded, machine-readable PLC evidence envelope.
     *
     * Richer outcomes such as TIMEOUT, INCONSISTENT and TRANSPORT_ERROR belong
     * in data.outcome. The top-level status deliberately stays in the common
     * PASS/FAIL/UNKNOWN/UNAVAILABLE vocabulary.
     *
     * @param array<string,mixed> $data
     * @param array<string,mixed> $metadata
     * @return array<string,mixed>
     */
    public static function build(
        string $schema,
        string $execution,
        string $status,
        array $data = [],
        array $metadata = []
    ): array {
        $schema = trim($schema);
        if ($schema === '' || strlen($schema) > 128 || preg_match('/^[a-z0-9][a-z0-9._-]*\.v[1-9][0-9]*$/', $schema) !== 1) {
            throw new InvalidArgumentException('PLC artifact schema must be a bounded versioned identifier ending in .vN.');
        }
        if (!in_array($execution, self::EXECUTION_STATES, true)) {
            throw new InvalidArgumentException('PLC artifact execution must be EXECUTED or NOT_EXECUTED.');
        }
        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('PLC artifact status must be PASS, FAIL, UNKNOWN, or UNAVAILABLE.');
        }

        return [
            'schema' => $schema,
            'execution' => $execution,
            'status' => $status,
            'data' => self::sanitize($data),
            'metadata' => self::sanitize($metadata),
        ];
    }

    /** @return mixed */
    public static function sanitize(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && self::isSecretKey($key)) {
            return self::REDACTED;
        }

        if (is_array($value)) {
            $sanitized = [];
            foreach ($value as $childKey => $childValue) {
                $childName = is_string($childKey) ? $childKey : null;
                $sanitized[$childKey] = self::sanitize($childValue, $childName);
            }
            return $sanitized;
        }

        if (is_string($value)) {
            if (strlen($value) > 4096) {
                return substr($value, 0, 4096) . '…';
            }
            return self::redactInlineSecrets($value);
        }

        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return $value;
        }

        return sprintf('[UNSERIALIZABLE:%s]', get_debug_type($value));
    }

    private static function isSecretKey(string $key): bool
    {
        return preg_match(
            '/(?:password|passwd|secret|token|credential|pin|authorization|auth[_-]?header|cookie|api[_-]?key)/i',
            $key
        ) === 1;
    }

    private static function redactInlineSecrets(string $value): string
    {
        $patterns = [
            '/(authorization\s*:\s*)([^\r\n]+)/i',
            '/(bearer\s+)[A-Za-z0-9._~+\/-]+=*/i',
        ];
        $replacements = ['$1' . self::REDACTED, '$1' . self::REDACTED];
        return (string)preg_replace($patterns, $replacements, $value);
    }
}
