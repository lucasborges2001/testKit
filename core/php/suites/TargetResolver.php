<?php
declare(strict_types=1);

namespace Testkit\Core\Suites;

use Testkit\Core\Common\Env;

final class TargetResolver
{
    /**
     * @return array<int,string>
     */
    public static function resolve(string $target): array
    {
        $map = [
            'all' => ['back_php', 'back_python', 'front_php', 'front_js', 'infra_php'],
            'back' => ['back_php', 'back_python'],
            'front' => ['front_php', 'front_js'],
            'infra' => ['infra_php'],
            'public_html' => ['front_php', 'front_js'],
            'back-php' => ['back_php'],
            'back-py' => ['back_python'],
            'back-python' => ['back_python'],
            'python' => ['back_python'],
            'py' => ['back_python'],
            'front-php' => ['front_php'],
            'front-js' => ['front_js'],
            'infra-php' => ['infra_php'],
            'http' => ['infra_php'],
            'php' => ['back_php', 'front_php', 'infra_php'],
            'js' => ['front_js'],
            'smoke' => ['back_php', 'back_python', 'front_php', 'front_js', 'infra_php'],
            'perf' => ['back_php', 'back_python', 'front_php', 'front_js', 'infra_php'],
            'stress' => ['back_php', 'back_python', 'front_php', 'front_js', 'infra_php'],
            'contract' => ['back_php', 'back_python', 'front_php', 'front_js', 'infra_php'],
            'critical' => ['back_php', 'back_python', 'front_php', 'front_js', 'infra_php'],
            'security' => ['back_php', 'back_python', 'front_php', 'front_js', 'infra_php'],
            'slow' => ['back_php', 'back_python', 'front_php', 'front_js', 'infra_php'],
            'migration-contract' => ['migration_contract'],
            'migration' => ['migration_contract'],
            'migrations' => ['migration_contract'],
            'reference-contract' => ['reference_contract'],
            'references' => ['reference_contract'],
            'php-references' => ['reference_contract'],
        ];

        $envKey = 'TESTKIT_TARGET_' . strtoupper(str_replace('-', '_', $target));
        $envVal = Env::string($envKey, '');

        if ($envVal !== '') {
            $parts = array_filter(array_map('trim', explode(',', $envVal)));
            $suites = [];
            $validSuites = ['back_php', 'back_python', 'front_php', 'front_js', 'infra_php', 'migration_contract', 'reference_contract'];

            foreach ($parts as $suite) {
                if (!in_array($suite, $validSuites, true)) {
                    fwrite(STDERR, "Error en {$envKey}: suite '{$suite}' no reconocida. Valores validos: " . implode('|', $validSuites) . "\n");
                    exit(3);
                }
                $suites[] = $suite;
            }

            return array_values(array_unique($suites));
        }

        return $map[$target] ?? [];
    }
}
