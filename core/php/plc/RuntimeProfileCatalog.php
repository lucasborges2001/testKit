<?php
declare(strict_types=1);

namespace Testkit\Core\Plc;

final class RuntimeProfileCatalog
{
    public const AUTO = 'auto';
    public const WAGO_PFC200_CODESYS2 = 'wago-pfc200-codesys2';
    public const WAGO_PFC200_ERUNTIME = 'wago-pfc200-eruntime';

    /**
     * @return array<string,array<string,mixed>>
     */
    public static function all(): array
    {
        return [
            self::WAGO_PFC200_CODESYS2 => [
                'id' => self::WAGO_PFC200_CODESYS2,
                'vendor' => 'wago',
                'family' => 'pfc200',
                'runtime' => 'codesys2',
                'transport' => 'modbus-tcp',
                'probes' => [
                    [
                        'id' => 'constants',
                        'address' => 0x2002,
                        'quantity' => 3,
                        'rule' => 'equals',
                        'expected' => [0x1234, 0xAAAA, 0x5555],
                        'required' => true,
                    ],
                    [
                        'id' => 'plc_state',
                        'address' => 0x1040,
                        'quantity' => 1,
                        'rule' => 'one_of',
                        'expected' => [1, 2],
                        'required' => true,
                    ],
                ],
            ],
            self::WAGO_PFC200_ERUNTIME => [
                'id' => self::WAGO_PFC200_ERUNTIME,
                'vendor' => 'wago',
                'family' => 'pfc200',
                'runtime' => 'eruntime',
                'transport' => 'modbus-tcp',
                'probes' => [
                    [
                        'id' => 'constants',
                        'address' => 0xFAA0,
                        'quantity' => 3,
                        'rule' => 'equals',
                        'expected' => [0x1234, 0xAAAA, 0x5555],
                        'required' => true,
                    ],
                    [
                        'id' => 'plc_state',
                        'address' => 0xFA0D,
                        'quantity' => 1,
                        'rule' => 'one_of',
                        'expected' => [1, 2],
                        'required' => true,
                    ],
                    [
                        'id' => 'process_image_version',
                        'address' => 0xFA17,
                        'quantity' => 1,
                        'rule' => 'readable',
                        'required' => false,
                    ],
                    [
                        'id' => 'process_image_sizes',
                        'address' => 0xFA40,
                        'quantity' => 6,
                        'rule' => 'readable',
                        'required' => false,
                    ],
                ],
            ],
        ];
    }

    /** @return array<string,mixed>|null */
    public static function get(string $profileId): ?array
    {
        $profiles = self::all();
        return $profiles[$profileId] ?? null;
    }

    /** @return array<int,string> */
    public static function ids(): array
    {
        return array_keys(self::all());
    }
}
