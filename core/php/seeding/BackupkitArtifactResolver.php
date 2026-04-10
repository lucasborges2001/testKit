<?php
declare(strict_types=1);

namespace Testkit\Core\Seeding;

use RuntimeException;

final class BackupkitArtifactResolver
{
    /**
     * @return array<string,mixed>
     */
    public static function resolveFromEnv(): array
    {
        $explicitSnapshot = trim((string)(getenv('TEST_BASELINE_SNAPSHOT_FILE') ?: ''));
        if ($explicitSnapshot !== '') {
            return [
                'path' => self::assertFileExists($explicitSnapshot, 'snapshot explicit'),
                'source_type' => 'explicit_snapshot',
                'source_path' => self::normalizePath($explicitSnapshot),
                'metadata_path' => '',
                'report_path' => '',
                'sha256' => '',
                'size_bytes' => @filesize($explicitSnapshot) ?: null,
            ];
        }

        $metadataPath = trim((string)(getenv('TEST_BASELINE_BACKUPKIT_METADATA_JSON') ?: ''));
        if ($metadataPath !== '') {
            return self::fromMetadataJson($metadataPath);
        }

        $reportPath = trim((string)(getenv('TEST_BASELINE_BACKUPKIT_REPORT_JSON') ?: ''));
        if ($reportPath !== '') {
            return self::fromReportJson($reportPath);
        }

        throw new RuntimeException(
            'Snapshot baseline no resuelto. Definí TEST_BASELINE_SNAPSHOT_FILE o TEST_BASELINE_BACKUPKIT_METADATA_JSON o TEST_BASELINE_BACKUPKIT_REPORT_JSON.'
        );
    }

    /**
     * @return array<string,mixed>
     */
    public static function fromMetadataJson(string $metadataPath): array
    {
        $data = self::readJson($metadataPath, 'metadata backupkit');
        $artifactPath = trim((string)($data['path'] ?? ''));
        if ($artifactPath === '') {
            throw new RuntimeException('Metadata backupkit sin path utilizable: ' . $metadataPath);
        }

        return [
            'path' => self::assertFileExists($artifactPath, 'artifacto backupkit'),
            'source_type' => 'backupkit_metadata',
            'source_path' => self::normalizePath($artifactPath),
            'metadata_path' => self::normalizePath($metadataPath),
            'report_path' => '',
            'sha256' => trim((string)($data['sha256'] ?? '')),
            'size_bytes' => isset($data['size_bytes']) ? (int)$data['size_bytes'] : null,
            'project' => (string)($data['project'] ?? ''),
            'resource' => (string)($data['resource'] ?? ''),
            'engine' => (string)($data['engine'] ?? ''),
            'timestamp' => (string)($data['timestamp'] ?? ''),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public static function fromReportJson(string $reportPath): array
    {
        $data = self::readJson($reportPath, 'reporte backupkit');
        self::assertReportLooksSuccessful($data, $reportPath);

        $artifact = self::resolveArtifactFromReport($data);
        $artifactPath = trim((string)($artifact['path'] ?? ''));
        if ($artifactPath === '') {
            throw new RuntimeException('Reporte backupkit sin artifact.path utilizable: ' . $reportPath);
        }

        return [
            'path' => self::assertFileExists($artifactPath, 'artifacto reportado por backupkit'),
            'source_type' => 'backupkit_report',
            'source_path' => self::normalizePath($artifactPath),
            'metadata_path' => trim((string)($artifact['metadata_path'] ?? $artifact['metadata']['path'] ?? '')),
            'report_path' => self::normalizePath($reportPath),
            'sha256' => trim((string)($artifact['sha256'] ?? '')),
            'size_bytes' => isset($artifact['size_bytes']) ? (int)$artifact['size_bytes'] : null,
            'project' => (string)($artifact['project'] ?? $data['project'] ?? ''),
            'resource' => (string)($artifact['resource'] ?? $data['resource'] ?? ''),
            'engine' => (string)($artifact['engine'] ?? $data['engine'] ?? ''),
            'report_status' => (string)($data['final_status'] ?? $data['status'] ?? ''),
        ];
    }

    /**
     * @param array<string,mixed> $report
     * @return array<string,mixed>
     */
    private static function resolveArtifactFromReport(array $report): array
    {
        $artifacts = $report['artifacts'] ?? null;
        if (is_array($artifacts)) {
            foreach ($artifacts as $artifact) {
                if (is_array($artifact) && trim((string)($artifact['path'] ?? '')) !== '') {
                    return $artifact;
                }
            }
        }

        $legacy = $report['artifact'] ?? null;
        if (is_array($legacy) && trim((string)($legacy['path'] ?? '')) !== '') {
            return $legacy;
        }

        $restore = $report['restore_test'] ?? null;
        if (is_array($restore)) {
            $candidate = $restore['artifact'] ?? null;
            if (is_array($candidate) && trim((string)($candidate['path'] ?? '')) !== '') {
                return $candidate;
            }
        }

        throw new RuntimeException('No se encontró artifact.path en reporte backupkit.');
    }

    /**
     * @param array<string,mixed> $report
     */
    private static function assertReportLooksSuccessful(array $report, string $reportPath): void
    {
        $strict = self::envBool('TEST_BASELINE_REQUIRE_BACKUPKIT_SUCCESS', true);
        if (!$strict) {
            return;
        }

        $status = strtoupper(trim((string)($report['final_status'] ?? $report['status'] ?? '')));
        if ($status === '') {
            throw new RuntimeException(
                'Reporte backupkit sin final_status/status; no se puede tratar como baseline confiable: ' . $reportPath
            );
        }

        $bad = ['ERROR', 'FAIL', 'FAILED'];
        if (in_array($status, $bad, true)) {
            throw new RuntimeException(
                'Reporte backupkit no exitoso para baseline snapshot [' . $status . ']: ' . $reportPath
            );
        }
    }

    /**
     * @return array<string,mixed>
     */
    private static function readJson(string $path, string $label): array
    {
        $resolved = self::assertFileExists($path, $label);
        $raw = file_get_contents($resolved);
        if ($raw === false || trim($raw) === '') {
            throw new RuntimeException('No se pudo leer ' . $label . ': ' . $resolved);
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('JSON invalido en ' . $label . ': ' . $resolved);
        }

        return $data;
    }

    private static function assertFileExists(string $path, string $label): string
    {
        $resolved = self::normalizePath($path);
        if (!is_file($resolved)) {
            throw new RuntimeException('No existe ' . $label . ': ' . $resolved);
        }

        return $resolved;
    }

    private static function envBool(string $name, bool $default): bool
    {
        $raw = getenv($name);
        if ($raw === false) {
            return $default;
        }

        return in_array(strtolower(trim((string)$raw)), ['1', 'true', 'yes', 'on'], true);
    }

    private static function normalizePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
