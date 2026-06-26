<?php
declare(strict_types=1);

/**
 * ============================================================================
 * @file    testkit/core/php/reporting/model/ReportSummary.php
 * @brief   Compone el modelo consolidado de reporte a partir de artefactos.
 * ============================================================================
 */

namespace Base\TestKit\Reporting\Model;

require_once __DIR__ . '/ReportArtifact.php';
require_once __DIR__ . '/../support/ReportStatus.php';

use Base\TestKit\Reporting\Support\ReportStatus;

final class ReportSummary implements \JsonSerializable
{
    /**
     * @param list<ReportArtifact> $artifacts
     * @param list<string> $warnings
     */
    public function __construct(
        public readonly string $workspaceRoot,
        public readonly string $testkitRoot,
        public readonly array $artifactRoots,
        public readonly array $artifacts,
        public readonly string $overallStatus,
        public readonly array $warnings
    ) {
    }

    /**
     * @param list<ReportArtifact> $artifacts
     * @param list<string> $warnings
     */
    public static function build(
        string $workspaceRoot,
        string $testkitRoot,
        array $artifactRoots,
        array $artifacts,
        array $warnings = []
    ): self {
        $statuses = array_map(
            static fn (ReportArtifact $artifact): string => $artifact->status,
            $artifacts
        );

        $overallStatus = ReportStatus::worst($statuses);

        if ($artifacts === []) {
            $warnings[] = 'No se detectaron artefactos de reporte en las rutas candidatas.';
        }

        return new self(
            $workspaceRoot,
            $testkitRoot,
            $artifactRoots,
            $artifacts,
            $overallStatus,
            array_values(array_unique($warnings))
        );
    }

    /**
     * @return array<string, int>
     */
    public function statusCounts(): array
    {
        $counts = [];
        foreach (ReportStatus::supported() as $status) {
            $counts[$status] = 0;
        }

        foreach ($this->artifacts as $artifact) {
            $counts[$artifact->status] = ($counts[$artifact->status] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'overall_status' => $this->overallStatus,
            'workspace_root' => $this->workspaceRoot,
            'testkit_root' => $this->testkitRoot,
            'artifact_roots' => $this->artifactRoots,
            'status_counts' => $this->statusCounts(),
            'warnings' => $this->warnings,
            'artifacts' => $this->artifacts,
        ];
    }
}
