<?php
declare(strict_types=1);

namespace Testkit\Core\DbProfiling\Gate;

final class MysqlQueryGateEvidenceLoader
{
    private const MAX_ARTIFACTS = 20;
    private const ROOT_KEYS = ['schema_version', 'artifacts'];
    private const ARTIFACT_KEYS = ['path', 'sha256'];

    /** @return array<int,array<string,mixed>> */
    public static function load(string $manifestPath, ?string $allowedRoot = null): array
    {
        $manifest = MysqlQueryGateArtifactWriter::loadJson($manifestPath, 1048576);
        self::known($manifest, self::ROOT_KEYS, '$');
        if (($manifest['schema_version'] ?? null) !== MysqlQueryGateConfig::EVIDENCE_SCHEMA_VERSION) {
            throw new MysqlQueryGateException(
                'Unsupported evidence manifest schema.',
                '$.schema_version',
                'unsupported_gate_evidence_schema',
                MysqlQueryGateConfig::EXIT_INVALID_CONTRACT
            );
        }
        $artifacts = $manifest['artifacts'] ?? null;
        if (!is_array($artifacts) || !self::isList($artifacts) || count($artifacts) > self::MAX_ARTIFACTS) {
            throw new MysqlQueryGateException(
                'Evidence artifacts must be a list with at most 20 entries.',
                '$.artifacts',
                'invalid_gate_evidence_artifacts',
                MysqlQueryGateConfig::EXIT_INVALID_CONTRACT
            );
        }
        $root = $allowedRoot !== null && $allowedRoot !== '' ? realpath($allowedRoot) : realpath(dirname($manifestPath));
        if ($root === false) {
            throw new MysqlQueryGateException(
                'Unable to resolve evidence root.',
                '$.artifacts',
                'gate_evidence_root_unresolved',
                MysqlQueryGateConfig::EXIT_OPERATIONAL
            );
        }
        $out = [];
        foreach ($artifacts as $index => $artifactInput) {
            $path = '$.artifacts[' . $index . ']';
            if (!is_array($artifactInput) || self::isList($artifactInput)) {
                throw new MysqlQueryGateException('Evidence artifact must be an object.', $path, 'invalid_evidence_artifact');
            }
            self::known($artifactInput, self::ARTIFACT_KEYS, $path);
            $relative = is_string($artifactInput['path'] ?? null) ? trim((string)$artifactInput['path']) : '';
            $hash = is_string($artifactInput['sha256'] ?? null) ? strtolower(trim((string)$artifactInput['sha256'])) : '';
            if ($relative === '' || preg_match('#^(?:[A-Za-z]:[\\/]|/|https?://|file://)#i', $relative) === 1 || str_contains(str_replace('\\', '/', $relative), '../')) {
                throw new MysqlQueryGateException('Evidence path must be relative and local.', $path . '.path', 'invalid_evidence_path');
            }
            if (preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
                throw new MysqlQueryGateException('Evidence hash must be SHA-256.', $path . '.sha256', 'invalid_evidence_hash');
            }
            $candidate = realpath(dirname($manifestPath) . '/' . $relative);
            if ($candidate === false || !str_starts_with(str_replace('\\', '/', $candidate), rtrim(str_replace('\\', '/', $root), '/') . '/')) {
                throw new MysqlQueryGateException('Evidence path escapes the allowed root.', $path . '.path', 'evidence_path_traversal');
            }
            $actualHash = MysqlQueryGateArtifactWriter::fileHash($candidate);
            if (!hash_equals($hash, $actualHash)) {
                throw new MysqlQueryGateException('Evidence artifact hash mismatch.', $path . '.sha256', 'evidence_hash_mismatch', MysqlQueryGateConfig::EXIT_INCOMPATIBLE_INPUT);
            }
            $payload = MysqlQueryGateArtifactWriter::loadJson($candidate);
            $payload['_artifact_path'] = MysqlQueryGateFinding::safePath($relative);
            $payload['_artifact_hash'] = $actualHash;
            $out[] = $payload;
        }
        return $out;
    }

    /** @param array<string,mixed> $object @param array<int,string> $allowed */
    private static function known(array $object, array $allowed, string $path): void
    {
        foreach (array_keys($object) as $key) {
            if (!in_array((string)$key, $allowed, true)) {
                throw new MysqlQueryGateException('Unknown key.', $path . '.' . $key, 'unknown_evidence_key');
            }
        }
    }

    /** @param array<mixed> $value */
    private static function isList(array $value): bool
    {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }
}
