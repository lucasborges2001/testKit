<?php
declare(strict_types=1);

namespace Testkit\Core\Suites;

use Testkit\Core\Common\Env;
use Testkit\Core\Config\ContractRegistry;

final class TargetResolver
{
    /** @return array<int,string> */
    public static function resolve(string $target): array
    {
        $target = strtolower(trim($target));
        $category = ContractRegistry::categoryFor($target);
        if ($category !== '' && Env::string('TEST_CATEGORY', '') === '') {
            putenv('TEST_CATEGORY=' . $category);
            $_ENV['TEST_CATEGORY'] = $category;
            $_SERVER['TEST_CATEGORY'] = $category;
        }

        // Fase 2 conserva la redefinición heredada, pero la valida contra el
        // mismo registro. Fase 3 elimina TESTKIT_TARGET_* por completo.
        $envKey = 'TESTKIT_TARGET_' . strtoupper(str_replace('-', '_', $target));
        $envVal = Env::string($envKey, '');
        if ($envVal !== '') {
            $parts = array_values(array_filter(array_map('trim', explode(',', $envVal))));
            $validSuites = ContractRegistry::suiteIds();
            foreach ($parts as $suiteId) {
                if (!in_array($suiteId, $validSuites, true)) {
                    fwrite(
                        STDERR,
                        "Error en {$envKey}: suite '{$suiteId}' no reconocida. Valores validos: "
                        . implode('|', $validSuites) . "\n"
                    );
                    return [];
                }
            }
            return array_values(array_unique($parts));
        }

        return ContractRegistry::resolve($target);
    }
}
