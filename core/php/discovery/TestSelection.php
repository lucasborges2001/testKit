<?php
declare(strict_types=1);

namespace Testkit\Core\Discovery;

use InvalidArgumentException;
use RuntimeException;
use Testkit\Core\Common\Paths;

final class TestSelection
{
    private string $source;
    /** @var array<int,string> */
    private array $entries;
    private string $legacyMatch;
    private string $mode;
    private string $selectionFile;
    private bool $selectionFileExists;
    /** @var array<int,string> */
    private array $invalidEntries;
    /** @var array<int,string> */
    private array $errors;

    /**
     * @param array<int,string> $entries
     * @param array<int,string> $invalidEntries
     * @param array<int,string> $errors
     */
    private function __construct(
        string $source,
        array $entries,
        string $legacyMatch,
        string $mode,
        string $selectionFile = '',
        bool $selectionFileExists = false,
        array $invalidEntries = [],
        array $errors = []
    ) {
        $this->source = $source;
        $this->entries = array_values(array_unique($entries));
        $this->legacyMatch = $legacyMatch;
        $this->mode = in_array($mode, ['exact', 'substring'], true) ? $mode : 'exact';
        $this->selectionFile = $selectionFile;
        $this->selectionFileExists = $selectionFileExists;
        $this->invalidEntries = array_values(array_unique($invalidEntries));
        $this->errors = array_values(array_unique($errors));
    }

    /** @param array<string,mixed> $config */
    public static function fromConfig(array $config): self
    {
        $matchFile = trim((string)($config['match_file'] ?? ''));
        $matchList = trim((string)($config['match_list'] ?? ''));
        $match = trim((string)($config['match'] ?? ''));
        $mode = self::normalizeMode((string)($config['match_list_mode'] ?? $config['selection_match_mode'] ?? 'exact'));

        if ($matchFile !== '') {
            $resolved = self::resolveSelectionFile($matchFile);
            $entries = self::parseFile($resolved);
            return new self('match_file', $entries, '', $mode, Paths::relativeToRepo($resolved), true);
        }

        if ($matchList !== '') {
            return new self('match_list', self::parseList($matchList), '', $mode);
        }

        if ($match !== '') {
            // Backwards-compatible legacy behavior: TEST_MATCH remains substring-based.
            return new self('match', [], $match, 'substring');
        }

        return new self('none', [], '', 'exact');
    }

    public function source(): string
    {
        return $this->source;
    }

    /** @return array<int,string> */
    public function entries(): array
    {
        return $this->entries;
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function matches(string $rel): bool
    {
        $rel = self::normalizeDiscoveredRel($rel);

        if ($this->source === 'none') {
            return true;
        }

        if ($this->source === 'match') {
            return $this->legacyMatch === '' || stripos($rel, $this->legacyMatch) !== false;
        }

        if ($this->entries === []) {
            return false;
        }

        foreach ($this->entries as $entry) {
            if ($this->entryMatchesRel($entry, $rel)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int,string> $selectedFiles
     * @return array<string,mixed>
     */
    public function metadata(array $selectedFiles): array
    {
        $selectedFiles = array_values(array_unique(array_map(
            static fn(string $rel): string => self::normalizeDiscoveredRel($rel),
            array_filter($selectedFiles, static fn(string $rel): bool => trim($rel) !== '')
        )));
        sort($selectedFiles);

        $entries = $this->source === 'match' && $this->legacyMatch !== '' ? [$this->legacyMatch] : $this->entries;

        return [
            'selection_source' => $this->source,
            'selection_match_mode' => $this->source === 'match' ? 'legacy_substring' : $this->mode,
            'selection_entries_count' => $this->entriesCount(),
            'selection_entries' => $entries,
            'selection_unmatched_entries' => $this->unmatchedEntries($selectedFiles),
            'selection_invalid_entries' => $this->invalidEntries,
            'selection_errors' => $this->errors,
            'selection_file' => $this->selectionFile,
            'selection_match_file' => $this->selectionFile,
            'selection_file_exists' => $this->selectionFileExists,
            'selected_test_files' => $selectedFiles,
        ];
    }

    private function entriesCount(): int
    {
        if ($this->source === 'match') {
            return $this->legacyMatch !== '' ? 1 : 0;
        }

        return count($this->entries);
    }

    /**
     * @param array<int,string> $selectedFiles
     * @return array<int,string>
     */
    private function unmatchedEntries(array $selectedFiles): array
    {
        if (!in_array($this->source, ['match_file', 'match_list'], true)) {
            return [];
        }

        $unmatched = [];
        foreach ($this->entries as $entry) {
            $matched = false;
            foreach ($selectedFiles as $rel) {
                if ($this->entryMatchesRel($entry, $rel)) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                $unmatched[] = $entry;
            }
        }

        sort($unmatched);
        return $unmatched;
    }

    private function entryMatchesRel(string $entry, string $rel): bool
    {
        if ($this->mode === 'substring') {
            return stripos($rel, $entry) !== false;
        }

        return $rel === $entry;
    }

    private static function resolveSelectionFile(string $rawPath): string
    {
        $path = self::normalizeFilePath($rawPath);
        if ($path === '') {
            throw new InvalidArgumentException('TEST_MATCH_FILE no puede estar vacío.');
        }
        if (str_contains($path, "\0")) {
            throw new InvalidArgumentException('TEST_MATCH_FILE no puede contener byte NUL.');
        }
        if (self::hasTraversal($path)) {
            throw new InvalidArgumentException('TEST_MATCH_FILE no puede contener path traversal con "..".');
        }

        $resolved = self::isAbsolutePath($path)
            ? $path
            : Paths::normalize(Paths::repoRoot() . '/' . $path);

        $repoRoot = Paths::normalize(Paths::repoRoot());
        if ($repoRoot !== '' && $resolved !== $repoRoot && !str_starts_with($resolved, $repoRoot . '/')) {
            throw new InvalidArgumentException('TEST_MATCH_FILE debe resolver dentro del repo host.');
        }

        if (!is_file($resolved) || !is_readable($resolved)) {
            throw new RuntimeException('TEST_MATCH_FILE apunta a un archivo inexistente o ilegible: ' . $rawPath);
        }

        return $resolved;
    }

    /** @return array<int,string> */
    private static function parseFile(string $file): array
    {
        $lines = @file($file, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            throw new RuntimeException('No se pudo leer archivo de selección: ' . $file);
        }

        $entries = [];
        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $entries[] = self::normalizeSelectionEntry($line);
        }

        return array_values(array_unique($entries));
    }

    /** @return array<int,string> */
    private static function parseList(string $raw): array
    {
        $entries = [];
        foreach (explode(',', $raw) as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            $entries[] = self::normalizeSelectionEntry($entry);
        }

        return array_values(array_unique($entries));
    }

    private static function normalizeSelectionEntry(string $entry): string
    {
        $original = $entry;
        $entry = self::normalizeFilePath($entry);
        $entry = preg_replace('#^\./+#', '', $entry) ?: $entry;
        $entry = trim($entry, '/');

        if ($entry === '') {
            throw new InvalidArgumentException('Entrada vacía en selección de tests.');
        }
        if (str_contains($entry, "\0")) {
            throw new InvalidArgumentException('Entrada de selección inválida por byte NUL.');
        }
        if (self::isAbsolutePath($original) || self::isAbsolutePath($entry)) {
            throw new InvalidArgumentException('Las entradas de selección deben ser repo-relative: ' . $original);
        }
        if (self::hasTraversal($entry)) {
            throw new InvalidArgumentException('Entrada de selección inválida por path traversal: ' . $entry);
        }

        $resolved = Paths::normalize(Paths::repoRoot() . '/' . $entry);
        $repoRoot = Paths::normalize(Paths::repoRoot());
        if ($repoRoot !== '' && $resolved !== $repoRoot && !str_starts_with($resolved, $repoRoot . '/')) {
            throw new InvalidArgumentException('Entrada de selección resuelve fuera del repo host: ' . $entry);
        }

        return $entry;
    }

    private static function normalizeDiscoveredRel(string $rel): string
    {
        $rel = str_replace('\\', '/', trim($rel));
        $rel = preg_replace('#/+#', '/', $rel) ?: $rel;
        $rel = preg_replace('#^\./+#', '', $rel) ?: $rel;
        return trim($rel, '/');
    }

    private static function normalizeFilePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = preg_replace('#/+#', '/', $path) ?: $path;
        return $path;
    }

    private static function normalizeMode(string $mode): string
    {
        $mode = strtolower(trim($mode));
        return in_array($mode, ['exact', 'substring'], true) ? $mode : 'exact';
    }

    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:\//', $path) === 1;
    }

    private static function hasTraversal(string $path): bool
    {
        $parts = explode('/', str_replace('\\', '/', $path));
        foreach ($parts as $part) {
            if ($part === '..') {
                return true;
            }
        }

        return false;
    }
}
