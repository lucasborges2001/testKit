<?php
declare(strict_types=1);

namespace Testkit\Core\Reporting;

final class ReportFileNamer
{
    public static function suiteBaseName(string $suiteId, string $selectedModuleScope): string
    {
        $safeSuite = self::safeSlug($suiteId, 'suite');
        $scopeKey = self::scopeKey($selectedModuleScope);

        if ($scopeKey === 'global') {
            return $safeSuite;
        }

        return $safeSuite . '__' . $scopeKey;
    }

    public static function metaBaseName(string $target, string $selectedModuleScope): string
    {
        $safeTarget = self::safeSlug($target, 'all');
        $scopeKey = self::scopeKey($selectedModuleScope);

        if ($safeTarget === 'all' && $scopeKey === 'global') {
            return 'meta';
        }

        return 'meta__' . $safeTarget . '__' . $scopeKey;
    }

    public static function scopeKey(string $selectedModuleScope): string
    {
        $selectedModuleScope = trim($selectedModuleScope);
        if ($selectedModuleScope === '') {
            return 'global';
        }

        return self::safeSlug($selectedModuleScope, 'global');
    }

    public static function safeSlug(string $value, string $fallback): string
    {
        $value = preg_replace('/[^a-z0-9._-]+/i', '_', strtolower(trim($value))) ?: '';
        $value = trim($value, '._-');

        return $value !== '' ? $value : $fallback;
    }
}
