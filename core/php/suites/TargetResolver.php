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
            'all' => ['back_php', 'back_python', 'front_php', 'front_js'],
            'back' => ['back_php', 'back_python'],
            'front' => ['front_php', 'front_js'],
            'public_html' => ['front_php', 'front_js'],
            'back-php' => ['back_php'],
            'back-py' => ['back_python'],
            'back-python' => ['back_python'],
            'python' => ['back_python'],
            'py' => ['back_python'],
            'front-php' => ['front_php'],
            'front-js' => ['front_js'],
            'php' => ['back_php', 'front_php'],
            'js' => ['front_js'],
            'smoke' => ['back_php', 'back_python', 'front_php', 'front_js'],
            'perf' => ['back_php', 'back_python', 'front_php', 'front_js'],
            'stress' => ['back_php', 'back_python', 'front_php', 'front_js'],
            'contract' => ['back_php', 'back_python', 'front_php', 'front_js'],
            'critical' => ['back_php', 'back_python', 'front_php', 'front_js'],
            'slow' => ['back_php', 'back_python', 'front_php', 'front_js'],
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
            $validSuites = ['back_php', 'back_python', 'front_php', 'front_js', 'migration_contract', 'reference_contract'];

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
