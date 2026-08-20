<?php
declare(strict_types=1);

namespace Testkit\Core\Plc;

use Throwable;

final class RuntimeProfileDetector
{
    /**
     * @param callable(int,int):array<int,int> $reader
     * @return array<string,mixed>
     */
    public function detect(callable $reader, string $requestedProfile = RuntimeProfileCatalog::AUTO): array
    {
        $requestedProfile = trim($requestedProfile) === ''
            ? RuntimeProfileCatalog::AUTO
            : trim($requestedProfile);

        if ($requestedProfile !== RuntimeProfileCatalog::AUTO && RuntimeProfileCatalog::get($requestedProfile) === null) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown PLC runtime profile "%s". Supported: %s, auto.',
                $requestedProfile,
                implode(', ', RuntimeProfileCatalog::ids())
            ));
        }

        $profileResults = [];
        $matches = [];

        foreach (RuntimeProfileCatalog::all() as $profileId => $profile) {
            $evaluation = $this->evaluateProfile($reader, $profile);
            $profileResults[$profileId] = $evaluation;
            if (($evaluation['matched'] ?? false) === true) {
                $matches[] = $profileId;
            }
        }

        $detectedProfile = count($matches) === 1 ? $matches[0] : null;
        $status = match (count($matches)) {
            0 => 'UNKNOWN',
            1 => 'DETECTED',
            default => 'AMBIGUOUS',
        };

        if ($requestedProfile !== RuntimeProfileCatalog::AUTO) {
            if ($detectedProfile === $requestedProfile) {
                $status = 'DETECTED';
            } elseif ($detectedProfile !== null) {
                $status = 'PROFILE_MISMATCH';
            } elseif (count($matches) > 1) {
                $status = 'AMBIGUOUS';
            } else {
                $status = 'UNKNOWN';
            }
        }

        return [
            'status' => $status,
            'requestedProfile' => $requestedProfile,
            'detectedProfile' => $detectedProfile,
            'matchedProfiles' => $matches,
            'profiles' => $profileResults,
        ];
    }

    /**
     * @param callable(int,int):array<int,int> $reader
     * @param array<string,mixed> $profile
     * @return array<string,mixed>
     */
    private function evaluateProfile(callable $reader, array $profile): array
    {
        $probeResults = [];
        $requiredTotal = 0;
        $requiredPassed = 0;
        $optionalPassed = 0;

        foreach (($profile['probes'] ?? []) as $probe) {
            if (!is_array($probe)) {
                continue;
            }

            $required = ($probe['required'] ?? false) === true;
            if ($required) {
                $requiredTotal++;
            }

            $result = $this->runProbe($reader, $probe);
            $probeResults[] = $result;

            if (($result['passed'] ?? false) === true) {
                if ($required) {
                    $requiredPassed++;
                } else {
                    $optionalPassed++;
                }
            }
        }

        return [
            'profile' => $profile['id'] ?? null,
            'vendor' => $profile['vendor'] ?? null,
            'family' => $profile['family'] ?? null,
            'runtime' => $profile['runtime'] ?? null,
            'transport' => $profile['transport'] ?? null,
            'matched' => $requiredTotal > 0 && $requiredPassed === $requiredTotal,
            'requiredPassed' => $requiredPassed,
            'requiredTotal' => $requiredTotal,
            'optionalPassed' => $optionalPassed,
            'probes' => $probeResults,
        ];
    }

    /**
     * @param callable(int,int):array<int,int> $reader
     * @param array<string,mixed> $probe
     * @return array<string,mixed>
     */
    private function runProbe(callable $reader, array $probe): array
    {
        $id = (string)($probe['id'] ?? 'probe');
        $address = (int)($probe['address'] ?? -1);
        $quantity = (int)($probe['quantity'] ?? 0);
        $rule = (string)($probe['rule'] ?? 'readable');
        $required = ($probe['required'] ?? false) === true;

        $base = [
            'id' => $id,
            'address' => $address,
            'addressHex' => sprintf('0x%04X', $address),
            'quantity' => $quantity,
            'rule' => $rule,
            'required' => $required,
            'passed' => false,
        ];

        try {
            $words = $reader($address, $quantity);
            if (!is_array($words) || count($words) !== $quantity) {
                return array_merge($base, [
                    'errorStage' => 'register_count',
                    'error' => 'Probe reader returned unexpected register count.',
                ]);
            }
            foreach ($words as $word) {
                if (!is_int($word) || $word < 0 || $word > 0xFFFF) {
                    return array_merge($base, [
                        'errorStage' => 'register_value',
                        'error' => 'Probe reader returned a value outside UINT16.',
                    ]);
                }
            }

            $expected = $probe['expected'] ?? null;
            $passed = match ($rule) {
                'equals' => is_array($expected) && array_values($words) === array_values($expected),
                'one_of' => $quantity === 1 && is_array($expected) && in_array($words[0], $expected, true),
                'readable' => true,
                default => false,
            };

            $result = array_merge($base, [
                'passed' => $passed,
                'observed' => array_values($words),
            ]);
            if (is_array($expected)) {
                $result['expected'] = array_values($expected);
            }
            if (!$passed && !in_array($rule, ['equals', 'one_of', 'readable'], true)) {
                $result['errorStage'] = 'probe_rule';
                $result['error'] = 'Unsupported runtime profile probe rule.';
            }
            return $result;
        } catch (Throwable $e) {
            return array_merge($base, [
                'errorStage' => $e instanceof ModbusTcpReadOnlyException ? $e->stage() : 'reader_exception',
                'error' => $e->getMessage(),
            ]);
        }
    }
}
