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
        $kind = strtolower(Env::string('TESTKIT_SELECTOR_KIND', 'suite'));
        return self::resolveTyped($kind, $selectorName);
    }

    /** @return list<string> */
    public static function resolveTyped(string $kind, string $name): array
    {
        $definition = ContractRegistry::definition($kind, $name);
        if (!is_array($definition)) {
            return [];
        }

        return array_values((array)($definition['suites'] ?? []));
    }
}
