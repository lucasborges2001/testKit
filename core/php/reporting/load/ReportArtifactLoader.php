<?php
declare(strict_types=1);

/**
 * ============================================================================
 * @file    testkit/core/php/reporting/load/ReportArtifactLoader.php
 * @brief   Descubre artefactos de reporte y delega su parseo por formato.
 * ============================================================================
 */

namespace Base\TestKit\Reporting\Load;

require_once __DIR__ . '/ReportArtifactParser.php';
require_once __DIR__ . '/ReportJsonArtifactParser.php';
require_once __DIR__ . '/ReportTextArtifactParser.php';
require_once __DIR__ . '/ReportXmlArtifactParser.php';
require_once __DIR__ . '/../cli/ReportPaths.php';
require_once __DIR__ . '/../model/ReportArtifact.php';

use Base\TestKit\Reporting\Cli\ReportPaths;
use Base\TestKit\Reporting\Model\ReportArtifact;

final class ReportArtifactLoader
{
    /** @var list<string> */
    private const SUPPORTED_EXTENSIONS = ['json', 'md', 'txt', 'xml'];

    /** @var list<ReportArtifactParser> */
    private readonly array $parsers;

    /**
     * @param list<ReportArtifactParser>|null $parsers
     */
    public function __construct(?array $parsers = null)
    {
        $this->parsers = $parsers ?? [
            new ReportJsonArtifactParser(),
            new ReportTextArtifactParser(),
            new ReportXmlArtifactParser(),
        ];
    }

    /**
     * @return list<ReportArtifact>
     */
    public function load(ReportPaths $paths): array
    {
        $artifacts = [];

        foreach ($this->discoverFiles($paths->artifactRoots) as $file) {
            foreach ($this->parsers as $parser) {
                if (!$parser->supports($file)) {
                    continue;
                }

                $artifact = $parser->parse($file, $paths);
                if ($artifact !== null) {
                    $artifacts[] = $artifact;
                }
                break;
            }
        }

        usort(
            $artifacts,
            static fn (ReportArtifact $a, ReportArtifact $b): int => [$a->status, $a->path] <=> [$b->status, $b->path]
        );

        return $artifacts;
    }

    /**
     * @param list<string> $roots
     * @return list<string>
     */
    private function discoverFiles(array $roots): array
    {
        $files = [];

        foreach ($roots as $root) {
            if (is_file($root)) {
                if ($this->isSupported($root)) {
                    $files[] = $root;
                }
                continue;
            }

            if (!is_dir($root)) {
                continue;
            }

            $files = array_merge($files, $this->discoverFilesInDirectory($root));
        }

        sort($files);
        return array_values(array_unique($files));
    }

    /** @return list<string> */
    private function discoverFilesInDirectory(string $root): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo || !$item->isFile()) {
                continue;
            }

            $file = $item->getPathname();
            if ($this->isSupported($file) && !$this->isNoiseFile($file)) {
                $files[] = $file;
            }
        }

        return $files;
    }

    private function isSupported(string $file): bool
    {
        return in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), self::SUPPORTED_EXTENSIONS, true);
    }

    private function isNoiseFile(string $file): bool
    {
        $normalized = str_replace('\\', '/', $file);
        foreach (['/node_modules/', '/vendor/', '/.git/'] as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }
}
