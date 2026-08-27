<?php
declare(strict_types=1);

namespace Testkit\Core\Plc;

final class PlcAuditProbe
{
    private const STATES = ['UNKNOWN', 'CANDIDATE', 'INFERRED', 'CORROBORATED', 'VERIFIED', 'CONFLICT'];
    private const READ_ONLY_FUNCTIONS = [1, 2, 3, 4];

    /** @param array<string,mixed> $input */
    public function audit(array $input): array
    {
        $result = [
            'schema' => 'testkit.plc.audit.v1',
            'timestamp' => gmdate('c'),
            'source' => (string)($input['source'] ?? 'testkit'),
            'result' => [
                'target' => $this->section($input['target'] ?? []),
                'runtime' => $this->section($input['runtime'] ?? []),
                'task' => $this->section($input['task'] ?? []),
                'plcConfiguration' => $this->section($input['plcConfiguration'] ?? []),
                'ioMap' => $this->ioMap($input['ioMap'] ?? []),
            ],
            'evidence' => $this->list($input['evidence'] ?? []),
            'warnings' => [],
        ];

        foreach ($this->snapshotPlans($input['readonlySnapshots'] ?? []) as $plan) {
            foreach ($plan['windows'] as $window) {
                if (!in_array($window['function'], self::READ_ONLY_FUNCTIONS, true)) {
                    $result['warnings'][] = 'non-read-only function rejected: FC' . $window['function'];
                    $result['result']['ioMap']['state'] = 'CONFLICT';
                }
            }
        }

        return $result;
    }

    /** @param mixed $value */
    private function section($value): array
    {
        if (!is_array($value)) {
            return ['state' => 'UNKNOWN', 'values' => [], 'evidence' => []];
        }
        $state = (string)($value['state'] ?? 'UNKNOWN');
        return [
            'state' => in_array($state, self::STATES, true) ? $state : 'UNKNOWN',
            'values' => is_array($value['values'] ?? null) ? $value['values'] : [],
            'evidence' => $this->list($value['evidence'] ?? []),
        ];
    }

    /** @param mixed $value */
    private function ioMap($value): array
    {
        $section = $this->section(is_array($value) ? $value : []);
        $mappings = [];
        foreach ($this->list(is_array($value) ? ($value['mappings'] ?? []) : []) as $mapping) {
            if (!is_array($mapping)) {
                continue;
            }
            $state = (string)($mapping['state'] ?? 'UNKNOWN');
            $mapping['state'] = in_array($state, self::STATES, true) ? $state : 'UNKNOWN';
            $mappings[] = $mapping;
        }
        $section['mappings'] = $mappings;
        $section['conflicts'] = $this->conflicts($mappings);
        if ($section['conflicts'] !== []) {
            $section['state'] = 'CONFLICT';
        }
        return $section;
    }

    /** @param array<int,array<string,mixed>> $mappings */
    private function conflicts(array $mappings): array
    {
        $seen = [];
        $conflicts = [];
        foreach ($mappings as $mapping) {
            $address = (string)($mapping['address'] ?? '');
            $symbol = (string)($mapping['symbol'] ?? '');
            if ($address === '' || $symbol === '') {
                continue;
            }
            if (isset($seen[$address]) && $seen[$address] !== $symbol) {
                $conflicts[] = ['address' => $address, 'symbols' => [$seen[$address], $symbol]];
            }
            $seen[$address] = $symbol;
        }
        return $conflicts;
    }

    /** @param mixed $value @return array<int,mixed> */
    private function list($value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    /** @param mixed $value @return array<int,array<string,mixed>> */
    private function snapshotPlans($value): array
    {
        return array_filter($this->list($value), 'is_array');
    }
}
