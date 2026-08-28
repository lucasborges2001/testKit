<?php
declare(strict_types=1);

namespace Testkit\Core\Plc;

use InvalidArgumentException;
use Throwable;

final class CoherentSnapshotReader
{
    public const SCHEMA = 'testkit.plc-snapshot-read.v1';
    public const MAX_ATTEMPTS = 1024;

    /**
     * Read head -> payload -> tail and accept only a coherent committed snapshot.
     *
     * @param callable():mixed $readHead
     * @param callable():mixed $readPayload
     * @param callable():mixed $readTail
     * @param callable(mixed):mixed|null $decode
     * @param callable(mixed,mixed,mixed):bool|null $commitPredicate
     * @param callable(mixed,mixed,mixed):bool|null $versionRule
     * @return array{artifact:array<string,mixed>,snapshot:mixed}
     */
    public static function read(
        callable $readHead,
        callable $readPayload,
        callable $readTail,
        ?callable $decode = null,
        ?callable $commitPredicate = null,
        ?callable $versionRule = null,
        int $maxAttempts = 8
    ): array {
        if ($maxAttempts < 1 || $maxAttempts > self::MAX_ATTEMPTS) {
            throw new InvalidArgumentException(sprintf(
                'Snapshot maxAttempts must be between 1 and %d.',
                self::MAX_ATTEMPTS
            ));
        }

        $started = hrtime(true);
        $inconsistent = 0;
        $uncommitted = 0;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $head = $readHead();
                $payload = $readPayload();
                $tail = $readTail();
            } catch (Throwable $e) {
                return self::result(
                    'FAIL',
                    'TRANSPORT_ERROR',
                    $attempt,
                    $inconsistent,
                    $uncommitted,
                    $started,
                    null,
                    $e
                );
            }

            if ($head !== $tail) {
                $inconsistent++;
                continue;
            }

            if ($commitPredicate !== null) {
                try {
                    if ($commitPredicate($head, $payload, $tail) !== true) {
                        $uncommitted++;
                        continue;
                    }
                } catch (Throwable $e) {
                    return self::result(
                        'FAIL',
                        'FAIL',
                        $attempt,
                        $inconsistent,
                        $uncommitted,
                        $started,
                        null,
                        $e,
                        'commit_predicate'
                    );
                }
            }

            if ($versionRule !== null) {
                try {
                    if ($versionRule($head, $payload, $tail) !== true) {
                        $inconsistent++;
                        continue;
                    }
                } catch (Throwable $e) {
                    return self::result(
                        'FAIL',
                        'FAIL',
                        $attempt,
                        $inconsistent,
                        $uncommitted,
                        $started,
                        null,
                        $e,
                        'version_rule'
                    );
                }
            }

            try {
                $snapshot = $decode === null ? $payload : $decode($payload);
            } catch (Throwable $e) {
                return self::result(
                    'FAIL',
                    'FAIL',
                    $attempt,
                    $inconsistent,
                    $uncommitted,
                    $started,
                    null,
                    $e,
                    'decode'
                );
            }

            return self::result(
                'PASS',
                'PASS',
                $attempt,
                $inconsistent,
                $uncommitted,
                $started,
                $snapshot
            );
        }

        return self::result(
            'FAIL',
            $inconsistent > 0 ? 'INCONSISTENT' : 'TIMEOUT',
            $maxAttempts,
            $inconsistent,
            $uncommitted,
            $started,
            null
        );
    }

    /** @return array{artifact:array<string,mixed>,snapshot:mixed} */
    private static function result(
        string $status,
        string $outcome,
        int $attempts,
        int $inconsistent,
        int $uncommitted,
        int $started,
        mixed $snapshot,
        ?Throwable $error = null,
        ?string $failureStage = null
    ): array {
        $data = [
            'outcome' => $outcome,
            'attempts' => $attempts,
            'inconsistent_attempts' => $inconsistent,
            'uncommitted_attempts' => $uncommitted,
            'duration_ms' => (int)round((hrtime(true) - $started) / 1_000_000),
            'failure_stage' => $failureStage,
            'failure_reason' => $error?->getMessage(),
        ];

        if ($error instanceof ModbusTcpReadOnlyException || $error instanceof ModbusTcpFunctionalHilException) {
            $data['failure_stage'] = $error->stage();
        }

        return [
            'artifact' => PlcArtifact::build(self::SCHEMA, 'EXECUTED', $status, $data),
            'snapshot' => $snapshot,
        ];
    }
}
