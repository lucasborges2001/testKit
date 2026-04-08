<?php
declare(strict_types=1);

namespace Testkit\Core\Discovery;

final class TestTagger
{
    /**
     * @return array<int,string>
     */
    public static function tagsFor(string $file, int $scanLines = 60, bool $tagsFromFilename = true, string $tagMap = ''): array
    {
        $tags = [];

        if ($tagsFromFilename) {
            $tags = array_merge($tags, self::tagsFromPath($file, $tagMap));
        }

        $tags = array_merge($tags, self::tagsFromHeader($file, $scanLines));
        $tags = array_map(static fn(string $tag): string => self::normalizeTag($tag), $tags);
        $tags = array_values(array_filter($tags, static fn(string $tag): bool => $tag !== ''));
        $tags = array_values(array_unique($tags));
        sort($tags);

        return $tags;
    }

    /**
     * @return array<int,string>
     */
    private static function tagsFromPath(string $file, string $tagMap = ''): array
    {
        $normalized = strtolower(str_replace('\\', '/', $file));
        $tags = [];

        $tokenMap = [
            'unit' => ['unit'],
            'integration' => ['integration', 'integracion'],
            'e2e' => ['e2e'],
            'smoke' => ['smoke'],
            'perf' => ['perf', 'performance', 'benchmark'],
            'stress' => ['stress', 'load', 'carga'],
            'slow' => ['slow'],
            'critical' => ['critical', 'critico', 'critica'],
            'contract' => ['contract', 'contrato'],
            'fragile' => ['fragile', 'flaky', 'inestable'],
        ];

        // Extender el mapa vía config: "tag:token1,token2;tag2:token3"
        if ($tagMap !== '') {
            foreach (explode(';', $tagMap) as $pair) {
                if (!str_contains($pair, ':')) continue;
                [$tag, $tokens] = explode(':', $pair, 2);
                $tag = trim($tag);
                if (!isset($tokenMap[$tag])) $tokenMap[$tag] = [];
                $tokenMap[$tag] = array_merge($tokenMap[$tag], array_map('trim', explode(',', $tokens)));
            }
        }

        foreach ($tokenMap as $tag => $tokens) {
            foreach ($tokens as $token) {
                if ($token === '') {
                    continue;
                }

                $quoted = preg_quote($token, '/');
                $matches =
                    str_contains($normalized, '/' . $token . '/') ||
                    str_contains($normalized, '_' . $token . '_') ||
                    preg_match('/(?:^|[\/_\-.])' . $quoted . '(?:[\/_\-.]|\.test\.(?:php|py|mjs|js|ts)$)/i', $normalized) === 1 ||
                    preg_match('/(?:_|-|\.)' . $quoted . '\.test\.(?:php|py|mjs|js|ts)$/i', $normalized) === 1;

                if ($matches) {
                    $tags[] = $tag;
                    break;
                }
            }
        }

        return $tags;
    }

    /**
     * @return array<int,string>
     */
    private static function tagsFromHeader(string $file, int $scanLines): array
    {
        $fh = @fopen($file, 'rb');
        if (!is_resource($fh)) {
            return [];
        }

        $tags = [];
        $lineNo = 0;
        while (($line = fgets($fh)) !== false) {
            $lineNo++;
            if ($lineNo > $scanLines) {
                break;
            }

            $trimmed = trim((string)$line);
            if ($trimmed === '') {
                continue;
            }

            if (preg_match('/(?:@tags|tags\s*:|#\s*tags\s*:|\*\s*tags\s*:|scope\s*:|#\s*scope\s*:|\*\s*scope\s*:)(.+)$/i', $trimmed, $m) === 1) {
                $raw = strtolower((string)$m[1]);
                $parts = preg_split('/[,;|\s]+/', $raw) ?: [];
                foreach ($parts as $part) {
                    $part = trim($part);
                    if ($part !== '') {
                        $tags[] = $part;
                    }
                }
            }
        }

        fclose($fh);
        return $tags;
    }

    private static function normalizeTag(string $tag): string
    {
        $tag = strtolower(trim($tag));
        if ($tag === '') {
            return '';
        }

        $aliasMap = [
            'performance' => 'perf',
            'benchmark' => 'perf',
            'load' => 'stress',
            'carga' => 'stress',
            'contracts' => 'contract',
            'critico' => 'critical',
            'critica' => 'critical',
            'flaky' => 'fragile',
            'inestable' => 'fragile',
        ];

        return $aliasMap[$tag] ?? $tag;
    }
}
