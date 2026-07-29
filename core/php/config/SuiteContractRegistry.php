<?php
declare(strict_types=1);

namespace Testkit\Core\Config;

final class SuiteContractRegistry
{
    /**
     * @return array{contract_version:int,capabilities:array<string,mixed>,hazards:array<string,mixed>}
     */
    public static function contractForSuite(string $suiteId, string $language): array
    {
        $contract = ContractRegistry::suiteContract($suiteId, $language);
        return [
            'contract_version' => (int)$contract['contract_version'],
            'capabilities' => (array)$contract['capabilities'],
            'hazards' => (array)$contract['hazards'],
        ];
    }

    /** @return array<string,mixed> */
    public static function capabilities(string $suiteId, string $language): array
    {
        return (array)ContractRegistry::suiteContract($suiteId, $language)['capabilities'];
    }

    /** @return array<string,mixed> */
    public static function hazards(string $suiteId): array
    {
        return (array)ContractRegistry::suiteContract($suiteId)['hazards'];
    }
}
