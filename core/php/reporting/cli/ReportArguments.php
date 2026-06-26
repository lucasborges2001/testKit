<?php
declare(strict_types=1);

/**
 * ============================================================================
 * @file    testkit/core/php/reporting/cli/ReportArguments.php
 * @brief   Parsea y normaliza argumentos CLI del entrypoint de reportes TestKit.
 * ============================================================================
 */

namespace Base\TestKit\Reporting\Cli;

require_once __DIR__ . '/../support/ReportFormat.php';
require_once __DIR__ . '/../support/ReportStatus.php';

use Base\TestKit\Reporting\Support\ReportFormat;
use Base\TestKit\Reporting\Support\ReportStatus;

final class ReportArguments
{
    public function __construct(
        public readonly string $format,
        public readonly ?string $outputPath,
        public readonly ?string $workspaceRoot,
        public readonly ?string $artifactsRoot,
        public readonly string $failOn,
        public readonly bool $showHelp,
        public readonly bool $showVersion,
        /** @var list<string> */
        public readonly array $positionalPaths,
        /** @var list<string> */
        public readonly array $warnings
    ) {
    }

    /**
     * @param list<string> $argv
     */
    public static function fromArgv(array $argv): self
    {
        $format = ReportFormat::TEXT;
        $outputPath = null;
        $workspaceRoot = null;
        $artifactsRoot = null;
        $failOn = ReportStatus::FAILED;
        $showHelp = false;
        $showVersion = false;
        $positionals = [];
        $warnings = [];

        $count = count($argv);
        for ($i = 1; $i < $count; $i++) {
            $token = (string) $argv[$i];

            if ($token === '--help' || $token === '-h') {
                $showHelp = true;
                continue;
            }

            if ($token === '--version') {
                $showVersion = true;
                continue;
            }

            if ($token === '--json') {
                $format = ReportFormat::JSON;
                continue;
            }

            if ($token === '--html') {
                $format = ReportFormat::HTML;
                continue;
            }

            if ($token === '--text') {
                $format = ReportFormat::TEXT;
                continue;
            }

            if (str_starts_with($token, '--format=')) {
                $format = substr($token, strlen('--format='));
                continue;
            }

            if ($token === '--format') {
                $format = self::requireValue($argv, ++$i, '--format');
                continue;
            }

            if (str_starts_with($token, '--output=')) {
                $outputPath = substr($token, strlen('--output='));
                continue;
            }

            if ($token === '--output' || $token === '-o') {
                $outputPath = self::requireValue($argv, ++$i, $token);
                continue;
            }

            if (str_starts_with($token, '--workspace=')) {
                $workspaceRoot = substr($token, strlen('--workspace='));
                continue;
            }

            if (str_starts_with($token, '--root=')) {
                $workspaceRoot = substr($token, strlen('--root='));
                continue;
            }

            if ($token === '--workspace' || $token === '--root') {
                $workspaceRoot = self::requireValue($argv, ++$i, $token);
                continue;
            }

            foreach (['--artifacts=', '--artifacts-root=', '--reports='] as $prefix) {
                if (str_starts_with($token, $prefix)) {
                    $artifactsRoot = substr($token, strlen($prefix));
                    continue 2;
                }
            }

            if ($token === '--artifacts' || $token === '--artifacts-root' || $token === '--reports') {
                $artifactsRoot = self::requireValue($argv, ++$i, $token);
                continue;
            }

            if (str_starts_with($token, '--fail-on=')) {
                $failOn = substr($token, strlen('--fail-on='));
                continue;
            }

            if ($token === '--fail-on') {
                $failOn = self::requireValue($argv, ++$i, '--fail-on');
                continue;
            }

            if (str_starts_with($token, '-')) {
                $warnings[] = sprintf('Argumento no reconocido preservado como advertencia: %s', $token);
                continue;
            }

            $positionals[] = $token;
        }

        $failOn = self::normalizeFailOn($failOn);

        return new self(
            ReportFormat::normalize($format),
            self::nullableString($outputPath),
            self::nullableString($workspaceRoot),
            self::nullableString($artifactsRoot),
            $failOn,
            $showHelp,
            $showVersion,
            $positionals,
            $warnings
        );
    }

    /**
     * @param list<string> $argv
     */
    private static function requireValue(array $argv, int $index, string $option): string
    {
        if (!array_key_exists($index, $argv) || str_starts_with((string) $argv[$index], '-')) {
            throw new \InvalidArgumentException(sprintf('Falta valor para %s', $option));
        }

        return (string) $argv[$index];
    }

    private static function normalizeFailOn(string $failOn): string
    {
        $normalized = strtolower(trim($failOn));
        $normalized = str_replace([' ', '-'], '_', $normalized);

        if ($normalized === 'never' || $normalized === 'none' || $normalized === 'off') {
            return 'never';
        }

        if (!in_array($normalized, [ReportStatus::FAILED, ReportStatus::REQUIRES_REVIEW, ReportStatus::UNAVAILABLE, ReportStatus::UNKNOWN], true)) {
            throw new \InvalidArgumentException(
                'Valor inválido para --fail-on. Usar: failed, requires_review, unavailable, unknown o never.'
            );
        }

        return $normalized;
    }

    private static function nullableString(?string $value): ?string
    {
        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }
}
