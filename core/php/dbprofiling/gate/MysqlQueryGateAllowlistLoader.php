<?php
declare(strict_types=1);

namespace Testkit\Core\DbProfiling\Gate;

final class MysqlQueryGateAllowlistLoader
{
    private const MAX_FILE_BYTES = 2097152;
    private const MAX_ENTRIES = 500;
    private const ROOT_KEYS = ['schema_version', 'allowlist'];
    private const ALLOWLIST_KEYS = ['id', 'description', 'entries', 'maximum_duration_days'];
    private const ENTRY_KEYS = ['id', 'selectors', 'reason', 'owner', 'ticket', 'notes', 'created_at', 'expires_at'];
    private const NON_SUPPRESSIBLE = [
        'evidence.invalid',
        'allowlist.invalid',
        'security.secret_leakage',
        'security.path_traversal',
        'artifact.write_error',
    ];

    /** @return array<string,mixed> */
    public static function load(string $path): array
    {
        return self::validate(MysqlQueryGateArtifactWriter::loadJson($path, self::MAX_FILE_BYTES));
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public static function validate(array $payload): array
    {
        self::known($payload, self::ROOT_KEYS, '$');
        if (($payload['schema_version'] ?? null) !== MysqlQueryGateConfig::ALLOWLIST_SCHEMA_VERSION) {
            throw self::invalid('Unsupported allowlist schema.', '$.schema_version', 'unsupported_allowlist_schema');
        }
        $root = self::object($payload['allowlist'] ?? null, '$.allowlist');
        self::known($root, self::ALLOWLIST_KEYS, '$.allowlist');
        $id = self::id($root['id'] ?? null, '$.allowlist.id');
        $description = self::text($root['description'] ?? '', '$.allowlist.description', 500, true);
        $maximumDurationDays = self::integer($root['maximum_duration_days'] ?? 90, 1, 365, '$.allowlist.maximum_duration_days');
        $entriesInput = $root['entries'] ?? [];
        if (!is_array($entriesInput) || !self::isList($entriesInput)) {
            throw self::invalid('Allowlist entries must be a list.', '$.allowlist.entries', 'invalid_allowlist_entries');
        }
        if (count($entriesInput) > self::MAX_ENTRIES) {
            throw self::invalid('Allowlist exceeds maximum entries.', '$.allowlist.entries', 'too_many_allowlist_entries');
        }

        $entries = [];
        $ids = [];
        foreach ($entriesInput as $index => $entryInput) {
            $path = '$.allowlist.entries[' . $index . ']';
            $entry = self::object($entryInput, $path);
            self::known($entry, self::ENTRY_KEYS, $path);
            $entryId = self::id($entry['id'] ?? null, $path . '.id');
            if (isset($ids[$entryId])) {
                throw self::invalid('Duplicate allowlist id.', $path . '.id', 'duplicate_allowlist_id');
            }
            $ids[$entryId] = true;
            $selectors = MysqlQueryGateLoader::selectors($entry['selectors'] ?? [], $path . '.selectors', false);
            self::assertSpecific($selectors, $path . '.selectors');
            foreach ((array)($selectors['category'] ?? []) as $category) {
                if (in_array($category, self::NON_SUPPRESSIBLE, true)) {
                    throw self::invalid('Allowlist may not suppress non-suppressible findings.', $path . '.selectors.category', 'allowlist_non_suppressible_category');
                }
            }
            $createdAt = self::timestamp($entry['created_at'] ?? null, $path . '.created_at');
            $expiresAt = self::timestamp($entry['expires_at'] ?? null, $path . '.expires_at');
            $created = strtotime($createdAt);
            $expires = strtotime($expiresAt);
            if ($created === false || $expires === false || $expires <= $created) {
                throw self::invalid('expires_at must be after created_at.', $path . '.expires_at', 'invalid_allowlist_expiration');
            }
            if (($expires - $created) > ($maximumDurationDays * 86400)) {
                throw self::invalid('Allowlist entry exceeds maximum duration.', $path . '.expires_at', 'allowlist_duration_exceeded');
            }
            $entries[] = [
                'id' => $entryId,
                'selectors' => $selectors,
                'reason' => self::text($entry['reason'] ?? null, $path . '.reason', 500),
                'owner' => self::text($entry['owner'] ?? null, $path . '.owner', 160),
                'ticket' => self::text($entry['ticket'] ?? '', $path . '.ticket', 160, true),
                'notes' => self::text($entry['notes'] ?? '', $path . '.notes', 500, true),
                'created_at' => $createdAt,
                'expires_at' => $expiresAt,
                'expired' => $expires < time(),
                'used' => false,
            ];
        }
        usort($entries, static fn(array $a, array $b): int => strcmp((string)$a['id'], (string)$b['id']));

        return [
            'schema_version' => MysqlQueryGateConfig::ALLOWLIST_SCHEMA_VERSION,
            'allowlist' => [
                'id' => $id,
                'description' => $description,
                'maximum_duration_days' => $maximumDurationDays,
                'entries' => $entries,
            ],
        ];
    }

    /** @param array<string,array<int,string>> $selectors */
    private static function assertSpecific(array $selectors, string $path): void
    {
        $specific = ['query_identity', 'policy_id', 'metric', 'plan_flag', 'source_finding_id', 'test_id', 'module_id', 'scenario_id', 'suite_id', 'subcategory'];
        if (array_intersect(array_keys($selectors), $specific) === []) {
            throw self::invalid(
                'Allowlist selector is too broad; add a specific identity selector.',
                $path,
                'allowlist_selector_too_broad'
            );
        }
    }

    private static function timestamp(mixed $value, string $path): string
    {
        $value = self::text($value, $path, 32);
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value) !== 1 || strtotime($value) === false) {
            throw self::invalid('Timestamp must be UTC in YYYY-MM-DDTHH:MM:SSZ format.', $path, 'invalid_allowlist_timestamp');
        }
        return $value;
    }

    /** @param array<string,mixed> $object @param array<int,string> $allowed */
    private static function known(array $object, array $allowed, string $path): void
    {
        foreach (array_keys($object) as $key) {
            if (!in_array((string)$key, $allowed, true)) {
                throw self::invalid('Unknown key.', $path . '.' . $key, 'unknown_allowlist_key');
            }
        }
    }

    /** @return array<string,mixed> */
    private static function object(mixed $value, string $path): array
    {
        if (!is_array($value) || self::isList($value)) {
            throw self::invalid('Expected JSON object.', $path, 'invalid_allowlist_object');
        }
        return $value;
    }

    private static function id(mixed $value, string $path): string
    {
        $value = self::text($value, $path, 160);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/', $value) !== 1) {
            throw self::invalid('Invalid allowlist identifier.', $path, 'invalid_allowlist_identifier');
        }
        return strtolower($value);
    }

    private static function text(mixed $value, string $path, int $max, bool $allowEmpty = false): string
    {
        if (!is_string($value)) {
            throw self::invalid('Expected string.', $path, 'invalid_allowlist_string');
        }
        $value = trim($value);
        if (!$allowEmpty && $value === '') {
            throw self::invalid('String may not be empty.', $path, 'empty_allowlist_string');
        }
        if (strlen($value) > $max) {
            throw self::invalid('String exceeds maximum length.', $path, 'allowlist_string_too_long');
        }
        return MysqlQueryGateArtifactWriter::sanitizeText($value, $max);
    }

    private static function integer(mixed $value, int $min, int $max, string $path): int
    {
        if (!is_int($value) || $value < $min || $value > $max) {
            throw self::invalid('Integer outside allowed range.', $path, 'invalid_allowlist_integer');
        }
        return $value;
    }

    private static function invalid(string $message, string $path, string $code): MysqlQueryGateException
    {
        return new MysqlQueryGateException($message, $path, $code, MysqlQueryGateConfig::EXIT_INVALID_CONTRACT);
    }

    /** @param array<mixed> $value */
    private static function isList(array $value): bool
    {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }
}
