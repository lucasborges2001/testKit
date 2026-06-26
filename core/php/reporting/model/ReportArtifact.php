<?php
declare(strict_types=1);

/**
 * ============================================================================
 * @file    testkit/core/php/reporting/model/ReportArtifact.php
 * @brief   Representa un artefacto de reporte detectado por TestKit.
 * ============================================================================
 */

namespace Base\TestKit\Reporting\Model;

require_once __DIR__ . '/../support/ReportStatus.php';

use Base\TestKit\Reporting\Support\ReportStatus;

final class ReportArtifact implements \JsonSerializable
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $path,
        public readonly string $type,
        public readonly string $status,
        public readonly ?int $exitCode,
        public readonly string $title,
        public readonly string $message,
        public readonly array $metadata = []
    ) {
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function fromData(
        string $path,
        string $type,
        ?string $status,
        ?int $exitCode,
        string $title,
        string $message,
        array $metadata = []
    ): self {
        return new self(
            $path,
            $type,
            ReportStatus::normalize($status),
            $exitCode,
            $title,
            $message,
            $metadata
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'path' => $this->path,
            'type' => $this->type,
            'status' => $this->status,
            'exit_code' => $this->exitCode,
            'title' => $this->title,
            'message' => $this->message,
            'metadata' => $this->metadata,
        ];
    }
}
