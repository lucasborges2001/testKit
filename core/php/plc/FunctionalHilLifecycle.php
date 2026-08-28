<?php
declare(strict_types=1);

namespace Testkit\Core\Plc;

use InvalidArgumentException;
use LogicException;
use Throwable;

final class FunctionalHilLifecycle
{
    public const SCHEMA = 'testkit.plc-functional-hil-session.v1';
    public const MAX_CLEANUP_WRITES = 16;
    public const MAX_VERIFIERS = 16;

    /** @var array<string,mixed>|null */
    private ?array $plan = null;

    /** @var array<string,mixed>|null */
    private ?array $cleanupReport = null;

    /** @var array<string,callable():bool> */
    private array $verifiers = [];

    private bool $armAttempted = false;
    private bool $armSucceeded = false;
    private bool $releaseAttempted = false;
    private bool $releaseSucceeded = false;
    private bool $armed = false;
    private bool $executing = false;

    public function __construct(private readonly FunctionalHilSession $session)
    {
    }

    /**
     * Execute one bounded HIL lifecycle against an already identity-gated session.
     *
     * Plan shape:
     *   arm:          {id:string,value:int}
     *   release:      {id:string,value:int}
     *   cleanupWrites:[{id:string,value:int}, ...]
     *   metadata:     optional safe artifact metadata
     *
     * The consumer owns all logical ids and values. TestKit never infers them.
     *
     * @param array<string,mixed> $plan
     * @param callable(self):mixed $scenario
     * @param array<string,callable():bool> $postCleanupVerifiers
     * @param callable():mixed|null $beforeArm
     * @return array<string,mixed>
     */
    public function execute(
        array $plan,
        callable $scenario,
        array $postCleanupVerifiers = [],
        ?callable $beforeArm = null
    ): array {
        if ($this->executing) {
            throw new LogicException('Functional HIL lifecycle is already executing.');
        }
        if ($this->plan !== null) {
            throw new LogicException('Functional HIL lifecycle instances are single-run.');
        }

        $this->executing = true;
        $started = hrtime(true);
        $failure = null;
        $failureStage = null;
        $scenarioSummary = null;

        try {
            $this->plan = $this->normalizePlan($plan);
            $this->verifiers = $this->normalizeVerifiers($postCleanupVerifiers);
        } catch (Throwable $e) {
            $this->executing = false;
            return PlcArtifact::build(self::SCHEMA, 'NOT_EXECUTED', 'FAIL', [
                'outcome' => 'PLAN_INVALID',
                'failure_stage' => 'plan_validation',
                'failure_reason' => $e->getMessage(),
                'duration_ms' => self::elapsedMs($started),
            ]);
        }

        if (!$this->session->writesAllowed()) {
            $this->executing = false;
            return PlcArtifact::build(self::SCHEMA, 'NOT_EXECUTED', 'FAIL', [
                'outcome' => 'GATE_BLOCKED',
                'gate' => $this->session->gateReport(),
                'arm_attempted' => false,
                'release_attempted' => false,
                'cleanup' => null,
                'duration_ms' => self::elapsedMs($started),
            ], $this->plan['metadata']);
        }

        if ($beforeArm !== null) {
            try {
                $pre = $beforeArm();
                if ($pre === false) {
                    throw new LogicException('Pre-arm condition returned false.');
                }
            } catch (Throwable $e) {
                $this->executing = false;
                return PlcArtifact::build(self::SCHEMA, 'EXECUTED', 'FAIL', [
                    'outcome' => 'PRE_ARM_FAILED',
                    'gate' => $this->session->gateReport(),
                    'arm_attempted' => false,
                    'release_attempted' => false,
                    'cleanup' => null,
                    'failure_stage' => 'pre_arm',
                    'failure_reason' => $e->getMessage(),
                    'duration_ms' => self::elapsedMs($started),
                ], $this->plan['metadata']);
            }
        }

        try {
            $this->armAttempted = true;
            $this->session->writeStimulus($this->plan['arm']['id'], $this->plan['arm']['value']);
            $this->armSucceeded = true;
            $this->armed = true;

            $scenarioResult = $scenario($this);
            $scenarioSummary = self::summarizeScenario($scenarioResult);
            if (!self::scenarioPassed($scenarioResult)) {
                throw new LogicException('Functional HIL scenario returned a failing result.');
            }

            $this->releaseAttempted = true;
            try {
                $this->session->writeStimulus($this->plan['release']['id'], $this->plan['release']['value']);
                $this->releaseSucceeded = true;
                $this->armed = false;
            } catch (Throwable $e) {
                $failure = $e;
                $failureStage = 'release';
            }
        } catch (Throwable $e) {
            $failure = $e;
            $failureStage = $this->armSucceeded ? 'scenario' : 'arm';
        } finally {
            $cleanup = $this->cleanup();
            $this->executing = false;
        }

        $cleanupOk = ($cleanup['status'] ?? null) === 'PASS';
        $passed = $failure === null && $cleanupOk;

        $data = [
            'outcome' => $passed ? 'PASS' : 'FAIL',
            'gate' => $this->session->gateReport(),
            'arm_attempted' => $this->armAttempted,
            'arm_succeeded' => $this->armSucceeded,
            'release_attempted' => $this->releaseAttempted,
            'release_succeeded' => $this->releaseSucceeded,
            'scenario' => $scenarioSummary,
            'cleanup' => $cleanup,
            'failure_stage' => $failureStage,
            'failure_reason' => $failure?->getMessage(),
            'duration_ms' => self::elapsedMs($started),
        ];
        if ($failure instanceof ModbusTcpFunctionalHilException || $failure instanceof ModbusTcpReadOnlyException) {
            $data['transport_stage'] = $failure->stage();
        }

        return PlcArtifact::build(
            self::SCHEMA,
            'EXECUTED',
            $passed ? 'PASS' : 'FAIL',
            $data,
            $this->plan['metadata']
        );
    }

    public function writeStimulus(string $stimulusId, int $value): void
    {
        $this->assertArmed('stimulus');
        $this->session->writeStimulus($stimulusId, $value);
    }

    public function heartbeat(string $stimulusId, int $value): void
    {
        $this->assertArmed('heartbeat');
        $this->session->writeStimulus($stimulusId, $value);
    }

    /**
     * Execute cleanup at most once. Subsequent calls return the original report
     * and perform zero additional writes/verifier calls.
     *
     * @return array<string,mixed>
     */
    public function cleanup(): array
    {
        if ($this->plan === null) {
            throw new LogicException('Functional HIL cleanup is unavailable before a validated lifecycle plan exists.');
        }

        return $this->performCleanup($this->verifiers);
    }

    /** @return array<string,mixed>|null */
    public function cleanupReport(): ?array
    {
        return $this->cleanupReport;
    }

    /** @param array<string,callable():bool> $verifiers @return array<string,mixed> */
    private function performCleanup(array $verifiers): array
    {
        if ($this->cleanupReport !== null) {
            return $this->cleanupReport;
        }

        $steps = [];
        $failures = 0;

        // An arm request may have reached the PLC even when its FC06 response was lost.
        // Therefore any arm attempt triggers a best-effort release before other cleanup.
        if ($this->armAttempted && !$this->releaseSucceeded) {
            $this->releaseAttempted = true;
            try {
                $this->session->writeStimulus($this->plan['release']['id'], $this->plan['release']['value']);
                $this->releaseSucceeded = true;
                $this->armed = false;
                $steps[] = ['id' => 'release', 'kind' => 'write', 'status' => 'PASS'];
            } catch (Throwable $e) {
                $failures++;
                $steps[] = [
                    'id' => 'release',
                    'kind' => 'write',
                    'status' => 'FAIL',
                    'stage' => self::exceptionStage($e),
                    'reason' => $e->getMessage(),
                ];
            }
        }

        foreach ($this->plan['cleanupWrites'] as $index => $write) {
            try {
                $this->session->writeStimulus($write['id'], $write['value']);
                $steps[] = ['id' => $write['id'], 'kind' => 'write', 'status' => 'PASS'];
            } catch (Throwable $e) {
                $failures++;
                $steps[] = [
                    'id' => $write['id'],
                    'kind' => 'write',
                    'status' => 'FAIL',
                    'stage' => self::exceptionStage($e),
                    'reason' => $e->getMessage(),
                ];
            }

            if ($index + 1 >= self::MAX_CLEANUP_WRITES) {
                break;
            }
        }

        foreach ($verifiers as $id => $verify) {
            try {
                $ok = $verify() === true;
                if (!$ok) {
                    $failures++;
                }
                $steps[] = [
                    'id' => $id,
                    'kind' => 'verify',
                    'status' => $ok ? 'PASS' : 'FAIL',
                ];
            } catch (Throwable $e) {
                $failures++;
                $steps[] = [
                    'id' => $id,
                    'kind' => 'verify',
                    'status' => 'FAIL',
                    'stage' => 'verifier_exception',
                    'reason' => $e->getMessage(),
                ];
            }
        }

        $this->cleanupReport = [
            'status' => $failures === 0 ? 'PASS' : 'FAIL',
            'failures' => $failures,
            'steps' => PlcArtifact::sanitize($steps),
            'bounded' => true,
            'idempotent' => true,
        ];

        return $this->cleanupReport;
    }

    /** @param array<string,mixed> $plan @return array<string,mixed> */
    private function normalizePlan(array $plan): array
    {
        $allowedKeys = ['arm', 'release', 'cleanupWrites', 'metadata'];
        $unknown = array_diff(array_keys($plan), $allowedKeys);
        if ($unknown !== []) {
            throw new InvalidArgumentException('Functional HIL lifecycle plan contains unsupported fields.');
        }

        $allowedIds = array_fill_keys($this->session->stimulusIds(), true);
        $arm = self::normalizeWrite($plan['arm'] ?? null, 'arm', $allowedIds);
        $release = self::normalizeWrite($plan['release'] ?? null, 'release', $allowedIds);

        $cleanupWrites = $plan['cleanupWrites'] ?? [];
        if (!is_array($cleanupWrites) || !array_is_list($cleanupWrites)) {
            throw new InvalidArgumentException('cleanupWrites must be a list.');
        }
        if (count($cleanupWrites) > self::MAX_CLEANUP_WRITES) {
            throw new InvalidArgumentException('cleanupWrites exceeds the bounded cleanup budget.');
        }

        $normalizedCleanup = [];
        foreach ($cleanupWrites as $index => $write) {
            $normalizedCleanup[] = self::normalizeWrite($write, 'cleanupWrites.' . $index, $allowedIds);
        }

        $metadata = $plan['metadata'] ?? [];
        if (!is_array($metadata) || (array_is_list($metadata) && $metadata !== [])) {
            throw new InvalidArgumentException('Functional HIL lifecycle metadata must be an object/associative array.');
        }

        return [
            'arm' => $arm,
            'release' => $release,
            'cleanupWrites' => $normalizedCleanup,
            'metadata' => $metadata,
        ];
    }

    /**
     * @param array<string,bool> $allowedIds
     * @return array{id:string,value:int}
     */
    private static function normalizeWrite(mixed $candidate, string $label, array $allowedIds): array
    {
        if (!is_array($candidate) || array_is_list($candidate)) {
            throw new InvalidArgumentException($label . ' must be an object/associative array.');
        }
        if (array_diff(array_keys($candidate), ['id', 'value']) !== []) {
            throw new InvalidArgumentException($label . ' contains unsupported fields.');
        }
        $id = $candidate['id'] ?? null;
        $value = $candidate['value'] ?? null;
        if (!is_string($id) || !isset($allowedIds[$id])) {
            throw new InvalidArgumentException($label . ' id must be an exact allowlisted stimulus id.');
        }
        if (!is_int($value) || $value < 0 || $value > 0xFFFF) {
            throw new InvalidArgumentException($label . ' value must fit UINT16.');
        }
        return ['id' => $id, 'value' => $value];
    }

    /** @param array<string,callable():bool> $verifiers @return array<string,callable():bool> */
    private function normalizeVerifiers(array $verifiers): array
    {
        if (count($verifiers) > self::MAX_VERIFIERS) {
            throw new InvalidArgumentException('Post-cleanup verifiers exceed the bounded verifier budget.');
        }
        $normalized = [];
        foreach ($verifiers as $id => $verify) {
            if (!is_string($id) || preg_match('/^[a-z][a-z0-9._-]{0,63}$/', $id) !== 1 || !is_callable($verify)) {
                throw new InvalidArgumentException('Post-cleanup verifier ids must be safe strings mapped to callables.');
            }
            $normalized[$id] = $verify;
        }
        return $normalized;
    }

    private function assertArmed(string $operation): void
    {
        if (!$this->executing || !$this->armed) {
            throw new LogicException(sprintf('Functional HIL %s requires an active armed lifecycle.', $operation));
        }
    }

    private static function scenarioPassed(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (!is_array($value)) {
            return true;
        }
        if (array_key_exists('pass', $value)) {
            return $value['pass'] === true;
        }
        if (isset($value['status']) && is_string($value['status'])) {
            return strtoupper($value['status']) === 'PASS';
        }
        if (isset($value['artifact']) && is_array($value['artifact']) && is_string($value['artifact']['status'] ?? null)) {
            return strtoupper((string)$value['artifact']['status']) === 'PASS';
        }
        return true;
    }

    /** @return array<string,mixed>|null */
    private static function summarizeScenario(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return ['pass' => $value];
        }
        if (is_array($value)) {
            $summary = [];
            foreach (['status', 'outcome', 'pass', 'transport_error', 'snapshot_inconsistent', 'cleanup_failure'] as $key) {
                if (array_key_exists($key, $value)) {
                    $summary[$key] = $value[$key];
                }
            }
            if (isset($value['artifact']) && is_array($value['artifact'])) {
                $summary['artifact'] = [
                    'schema' => $value['artifact']['schema'] ?? null,
                    'execution' => $value['artifact']['execution'] ?? null,
                    'status' => $value['artifact']['status'] ?? null,
                    'outcome' => $value['artifact']['data']['outcome'] ?? null,
                ];
            }
            return $summary === [] ? ['returned' => get_debug_type($value)] : $summary;
        }
        return ['returned' => get_debug_type($value)];
    }

    private static function exceptionStage(Throwable $e): string
    {
        if ($e instanceof ModbusTcpFunctionalHilException || $e instanceof ModbusTcpReadOnlyException) {
            return $e->stage();
        }
        return 'exception';
    }

    private static function elapsedMs(int $started): int
    {
        return (int)round((hrtime(true) - $started) / 1_000_000);
    }
}
