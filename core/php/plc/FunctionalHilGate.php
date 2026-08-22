<?php
declare(strict_types=1);

namespace Testkit\Core\Plc;

use InvalidArgumentException;

final class FunctionalHilGate
{
    public const SCHEMA = 'functional_hil_gate@1';

    /** @var list<string> */
    private const STATUSES = ['PASS', 'FAIL', 'UNKNOWN', 'UNAVAILABLE'];

    /** @var list<string> */
    private const COMPONENTS = ['runtime', 'application', 'bridge'];

    public const MAX_METADATA_ENTRIES = 16;
    public const MAX_TEXT_LENGTH = 160;

    /**
     * Normalize host-owned identity evidence without interpreting consumer semantics.
     *
     * @param array<string,mixed> $evidence
     * @return array<string,mixed>
     */
    public static function normalize(array $evidence): array
    {
        self::rejectUnknownKeys($evidence, ['schema', 'runtime', 'application', 'bridge', 'metadata'], 'gate');

        if (($evidence['schema'] ?? null) !== self::SCHEMA) {
            throw new InvalidArgumentException('Functional HIL gate schema must be functional_hil_gate@1.');
        }

        $normalized = ['schema' => self::SCHEMA];
        foreach (self::COMPONENTS as $component) {
            $value = $evidence[$component] ?? null;
            if (!is_array($value) || array_is_list($value)) {
                throw new InvalidArgumentException(sprintf('Functional HIL %s evidence must be an object/associative array.', $component));
            }
            $normalized[$component] = self::normalizeComponent($component, $value);
        }

        $metadata = $evidence['metadata'] ?? [];
        if (!is_array($metadata) || array_is_list($metadata) && $metadata !== []) {
            throw new InvalidArgumentException('Functional HIL gate metadata must be an object/associative array.');
        }
        $normalized['metadata'] = self::normalizeMetadata($metadata);
        $normalized['identities_pass'] = self::identitiesPass($normalized);

        return $normalized;
    }

    /** @param array<string,mixed> $normalized */
    public static function identitiesPass(array $normalized): bool
    {
        foreach (self::COMPONENTS as $component) {
            if (($normalized[$component]['status'] ?? null) !== 'PASS') {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<string,mixed> $value
     * @return array<string,mixed>
     */
    private static function normalizeComponent(string $component, array $value): array
    {
        self::rejectUnknownKeys($value, ['status', 'id', 'version', 'fingerprint', 'reason'], $component);

        $status = $value['status'] ?? null;
        if (!is_string($status) || !in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException(sprintf(
                'Functional HIL %s status must be PASS, FAIL, UNKNOWN, or UNAVAILABLE.',
                $component
            ));
        }

        $result = ['status' => $status];
        foreach (['id', 'version', 'fingerprint', 'reason'] as $field) {
            if (!array_key_exists($field, $value)) {
                continue;
            }
            $result[$field] = self::boundedText($value[$field], sprintf('%s.%s', $component, $field));
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $metadata
     * @return array<string,bool|float|int|string|null>
     */
    private static function normalizeMetadata(array $metadata): array
    {
        if (count($metadata) > self::MAX_METADATA_ENTRIES) {
            throw new InvalidArgumentException('Functional HIL gate metadata exceeds entry budget.');
        }

        $normalized = [];
        foreach ($metadata as $key => $value) {
            if (!is_string($key) || preg_match('/^[a-z][a-z0-9._-]{0,63}$/', $key) !== 1) {
                throw new InvalidArgumentException('Functional HIL metadata key is invalid.');
            }
            if (preg_match('/(?:password|passwd|secret|token|credential|register|address|%[iq])/i', $key) === 1) {
                throw new InvalidArgumentException('Functional HIL metadata key is prohibited by sanitization policy.');
            }
            if (is_string($value)) {
                $normalized[$key] = self::boundedText($value, 'metadata.' . $key);
                continue;
            }
            if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
                $normalized[$key] = $value;
                continue;
            }
            throw new InvalidArgumentException('Functional HIL metadata values must be bounded scalars.');
        }

        ksort($normalized);
        return $normalized;
    }

    private static function boundedText(mixed $value, string $label): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException($label . ' must be a string.');
        }
        $value = trim($value);
        if ($value === '' || strlen($value) > self::MAX_TEXT_LENGTH || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException($label . ' must be non-empty bounded printable text.');
        }
        return $value;
    }

    /**
     * @param array<string,mixed> $value
     * @param list<string> $allowed
     */
    private static function rejectUnknownKeys(array $value, array $allowed, string $label): void
    {
        $unknown = array_diff(array_keys($value), $allowed);
        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf(
                'Functional HIL %s contains unsupported field "%s".',
                $label,
                (string)reset($unknown)
            ));
        }
    }
}
