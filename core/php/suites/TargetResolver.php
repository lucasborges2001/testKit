<?php
declare(strict_types=1);

namespace Testkit\Core\Suites;

use Testkit\Core\Common\Env;
use Testkit\Core\Config\ContractRegistry;

final class TargetResolver
{
    /** @return list<string> */
    public static function resolve(string $selectorName): array
    {
        $kind = strtolower(Env::string('TESTKIT_SELECTOR_KIND', ''));
        if ($kind === '') {
            return [];
        }
        return self::resolveTyped($kind, $selectorName);
    }

    /** @return list<string> */
    public static function resolveTyped(string $kind, string $name): array
    {
        $definition = ContractRegistry::definition($kind, $name);
        if (!is_array($definition)) {
            return [];
        }

        if ($kind === 'category') {
            $category = (string)($definition['category'] ?? $name);
            putenv('TEST_CATEGORY=' . $category);
            $_ENV['TEST_CATEGORY'] = $category;
            $_SERVER['TEST_CATEGORY'] = $category;
        }

        return array_values((array)($definition['suites'] ?? []));
    }
}
