<?php
declare(strict_types=1);

namespace Testkit\Core\Plc;

use InvalidArgumentException;

final class ReadOnlyApplicationMapValidator
{
    /** One diagnostic plan is intentionally bounded to a small, deterministic number of TCP requests. */
    public const MAX_WINDOWS = 16;

    /** 1024 registers = 2 KiB of Modbus word payload across the whole one-shot plan. */
    public const MAX_TOTAL_REGISTERS = 1024;

    /** Optional pacing is bounded so a malformed descriptor cannot stall a run indefinitely. */
    public const MAX_INTER_REQUEST_DELAY_MS = 1000;

    /**
     * Validate and normalize a host-owned read-only application map descriptor.
     *
     * @param array<string,mixed> $plan
     * @return array<string,mixed>
     */
    public static function normalize(array $plan): array
    {
        $id = self::identifier($plan['id'] ?? null, 'plan id');

        $profiles = $plan['supportedRuntimeProfiles'] ?? null;
        if (!is_array($profiles) || !array_is_list($profiles) || $profiles === []) {
            throw new InvalidArgumentException('supportedRuntimeProfiles must be a non-empty list.');
        }

        $normalizedProfiles = [];
        foreach ($profiles as $profile) {
            if (!is_string($profile) || trim($profile) === '') {
                throw new InvalidArgumentException('supportedRuntimeProfiles entries must be non-empty strings.');
            }
            $profile = trim($profile);
            if ($profile === RuntimeProfileCatalog::AUTO || RuntimeProfileCatalog::get($profile) === null) {
                throw new InvalidArgumentException(sprintf('Unknown application-map runtime profile "%s".', $profile));
            }
            if (in_array($profile, $normalizedProfiles, true)) {
                throw new InvalidArgumentException(sprintf('Duplicate supported runtime profile "%s".', $profile));
            }
            $normalizedProfiles[] = $profile;
        }

        $windows = $plan['windows'] ?? null;
        if (!is_array($windows) || !array_is_list($windows) || $windows === []) {
            throw new InvalidArgumentException('windows must be a non-empty list.');
        }
        if (count($windows) > self::MAX_WINDOWS) {
            throw new InvalidArgumentException(sprintf(
                'Read-only application map exceeds max windows budget: got=%d max=%d.',
                count($windows),
                self::MAX_WINDOWS
            ));
        }

        $normalizedWindows = [];
        $windowIds = [];
        $totalRegisters = 0;

        foreach ($windows as $index => $window) {
            if (!is_array($window)) {
                throw new InvalidArgumentException(sprintf('Window %d must be an object/array.', $index));
            }

            $windowId = self::identifier($window['id'] ?? null, sprintf('window %d id', $index));
            if (isset($windowIds[$windowId])) {
                throw new InvalidArgumentException(sprintf('Duplicate window id "%s".', $windowId));
            }
            $windowIds[$windowId] = true;

            $function = $window['function'] ?? null;
            if (!is_int($function) || $function !== ModbusTcpReadOnlyClient::FC_READ_HOLDING_REGISTERS) {
                throw new InvalidArgumentException(sprintf(
                    'Window "%s" function must be integer FC03.',
                    $windowId
                ));
            }

            $startAddress = $window['startAddress'] ?? null;
            if (!is_int($startAddress) || $startAddress < 0 || $startAddress > 0xFFFF) {
                throw new InvalidArgumentException(sprintf(
                    'Window "%s" startAddress must be an integer between 0 and 65535.',
                    $windowId
                ));
            }

            $quantity = $window['quantity'] ?? null;
            if (!is_int($quantity) || $quantity < 1 || $quantity > 125) {
                throw new InvalidArgumentException(sprintf(
                    'Window "%s" quantity must be an integer between 1 and 125.',
                    $windowId
                ));
            }

            $endAddress = $startAddress + $quantity - 1;
            if ($endAddress > 0xFFFF) {
                throw new InvalidArgumentException(sprintf(
                    'Window "%s" range exceeds Modbus UINT16 address space.',
                    $windowId
                ));
            }

            $totalRegisters += $quantity;
            if ($totalRegisters > self::MAX_TOTAL_REGISTERS) {
                throw new InvalidArgumentException(sprintf(
                    'Read-only application map exceeds total register budget: got>%d max=%d.',
                    self::MAX_TOTAL_REGISTERS,
                    self::MAX_TOTAL_REGISTERS
                ));
            }

            $normalizedWindows[] = [
                'id' => $windowId,
                'function' => ModbusTcpReadOnlyClient::FC_READ_HOLDING_REGISTERS,
                'startAddress' => $startAddress,
                'endAddress' => $endAddress,
                'quantity' => $quantity,
            ];
        }

        $delayMs = $plan['interRequestDelayMs'] ?? 0;
        if (!is_int($delayMs) || $delayMs < 0 || $delayMs > self::MAX_INTER_REQUEST_DELAY_MS) {
            throw new InvalidArgumentException(sprintf(
                'interRequestDelayMs must be an integer between 0 and %d.',
                self::MAX_INTER_REQUEST_DELAY_MS
            ));
        }

        return [
            'id' => $id,
            'supportedRuntimeProfiles' => $normalizedProfiles,
            'windows' => $normalizedWindows,
            'interRequestDelayMs' => $delayMs,
            'windowCount' => count($normalizedWindows),
            'totalRegisters' => $totalRegisters,
            'budgets' => [
                'maxWindows' => self::MAX_WINDOWS,
                'maxRegistersPerRequest' => 125,
                'maxTotalRegisters' => self::MAX_TOTAL_REGISTERS,
                'maxInterRequestDelayMs' => self::MAX_INTER_REQUEST_DELAY_MS,
            ],
        ];
    }

    private static function identifier(mixed $value, string $label): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException($label . ' must be a string.');
        }
        $value = trim($value);
        if ($value === '' || strlen($value) > 128 || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $value) !== 1) {
            throw new InvalidArgumentException($label . ' must match [A-Za-z0-9][A-Za-z0-9._-]* and be <=128 chars.');
        }
        return $value;
    }
}
