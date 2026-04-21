<?php
declare(strict_types=1);

namespace Testkit\Core\Config;

final class ConfigSchema
{
    /**
     * @return array<string,mixed>
     */
    public static function inspectPayload(): array
    {
        return [
            'schema_version' => 1,
            'commands' => [
                ['command' => 'php runTest.php --help', 'purpose' => 'mostrar ayuda del runner'],
                ['command' => 'php runTest.php <target> --list', 'purpose' => 'listar selección sin ejecutar tests'],
                ['command' => 'php scripts/inspect.php config-schema --json', 'purpose' => 'serializar el esquema soportado'],
            ],
            'notes' => [
                'Valores bool inválidos y enteros no parseables deben quedar visibles vía warnings.',
                'Targets agregados son válidos, pero no son la primera corrida diagnóstica más nítida.',
                'migration-contract exige snapshot + shared + mysql + TEST_JOBS=1.',
            ],
        ];
    }
}
