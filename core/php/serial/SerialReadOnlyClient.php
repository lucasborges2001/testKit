<?php
declare(strict_types=1);
namespace Testkit\Core\Serial;

use Testkit\Core\Execution\ProcessRunner;

final class SerialReadOnlyClient
{
    public const STATUS_FRAME = 'FRAME';
    public const STAGE_TIMEOUT = 'timeout';
    public const STAGE_OVERFLOW = 'overflow';
    public const STAGE_DEVICE_ERROR = 'device_error';
    public const STAGE_CONFIG_ERROR = 'config_error';

    private const BAUDS = [50,75,110,134,150,200,300,600,1200,1800,2400,4800,9600,19200,38400,57600,115200,230400];
    private const TERMINATORS = ['CR' => "\r", 'LF' => "\n", 'CRLF' => "\r\n"];

    /** @var resource|null */
    private $stream = null;
    private string $buffer = '';
    /** @var array<string,string> */
    private array $terminators;

    /** @param list<string> $terminators */
    public function __construct(
        private readonly string $device,
        private readonly int $baud,
        private readonly int $dataBits,
        private readonly int $stopBits,
        private readonly string $parity,
        private readonly string $flowControl,
        private readonly int $timeoutMs,
        private readonly int $maxFrameBytes,
        array $terminators
    ) {
        $this->validateConfig($terminators);
        $normalized = [];
        foreach ($terminators as $name) {
            $upper = strtoupper(trim($name));
            $normalized[$upper] = self::TERMINATORS[$upper];
        }
        $this->terminators = $normalized;
    }

    public function __destruct()
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    /** @return array{frame:string,evidence:array<string,mixed>} */
    public function captureFrame(): array
    {
        $this->ensureOpen();
        $started = self::nowMs();
        $deadline = $started + $this->timeoutMs;

        while (true) {
            $match = $this->extractFrame(false);
            if ($match !== null) {
                return $this->result($match['frame'], $match['terminator'], $started);
            }
            $this->assertNotOverflowing();

            $remaining = $deadline - self::nowMs();
            if ($remaining <= 0) {
                $match = $this->extractFrame(true);
                if ($match !== null) {
                    return $this->result($match['frame'], $match['terminator'], $started);
                }
                throw new SerialReadOnlyException(self::STAGE_TIMEOUT, 'Serial frame terminator was not received before timeout.');
            }

            $read = [$this->stream]; $write = []; $except = [];
            $sec = intdiv($remaining, 1000); $usec = ($remaining % 1000) * 1000;
            $ready = @stream_select($read, $write, $except, $sec, $usec);
            if ($ready === false) {
                throw new SerialReadOnlyException(self::STAGE_DEVICE_ERROR, 'stream_select failed for serial device.');
            }
            if ($ready === 0) {
                continue;
            }
            $chunk = fread($this->stream, 4096);
            if ($chunk === false) {
                throw new SerialReadOnlyException(self::STAGE_DEVICE_ERROR, 'Unable to read serial device.');
            }
            if ($chunk === '') {
                if (feof($this->stream)) {
                    throw new SerialReadOnlyException(self::STAGE_DEVICE_ERROR, 'Serial device closed before a complete frame.');
                }
                continue;
            }
            $this->buffer .= $chunk;
        }
    }

    /** @param list<string> $terminators */
    private function validateConfig(array $terminators): void
    {
        if ($this->device === '' || $this->device[0] !== '/' || str_contains($this->device, "\0")) {
            throw new SerialReadOnlyException(self::STAGE_CONFIG_ERROR, 'device must be a non-empty absolute path.');
        }
        if (!in_array($this->baud, self::BAUDS, true)) {
            throw new SerialReadOnlyException(self::STAGE_CONFIG_ERROR, 'unsupported baud rate.');
        }
        if (!in_array($this->dataBits, [5,6,7,8], true)) {
            throw new SerialReadOnlyException(self::STAGE_CONFIG_ERROR, 'dataBits must be 5, 6, 7 or 8.');
        }
        if (!in_array($this->stopBits, [1,2], true)) {
            throw new SerialReadOnlyException(self::STAGE_CONFIG_ERROR, 'stopBits must be 1 or 2.');
        }
        if (!in_array($this->parity, ['none','even','odd'], true)) {
            throw new SerialReadOnlyException(self::STAGE_CONFIG_ERROR, 'parity must be none, even or odd.');
        }
        if (!in_array($this->flowControl, ['none','hardware','software'], true)) {
            throw new SerialReadOnlyException(self::STAGE_CONFIG_ERROR, 'flowControl must be none, hardware or software.');
        }
        if ($this->timeoutMs < 1 || $this->timeoutMs > 60000) {
            throw new SerialReadOnlyException(self::STAGE_CONFIG_ERROR, 'timeoutMs must be between 1 and 60000.');
        }
        if ($this->maxFrameBytes < 1 || $this->maxFrameBytes > 1048576) {
            throw new SerialReadOnlyException(self::STAGE_CONFIG_ERROR, 'maxFrameBytes must be between 1 and 1048576.');
        }
        if ($terminators === []) {
            throw new SerialReadOnlyException(self::STAGE_CONFIG_ERROR, 'at least one terminator is required.');
        }
        foreach ($terminators as $name) {
            if (!is_string($name) || !array_key_exists(strtoupper(trim($name)), self::TERMINATORS)) {
                throw new SerialReadOnlyException(self::STAGE_CONFIG_ERROR, 'terminators may contain only CR, LF or CRLF.');
            }
        }
    }

    private function ensureOpen(): void
    {
        if (is_resource($this->stream)) return;
        if (!file_exists($this->device)) {
            throw new SerialReadOnlyException(self::STAGE_DEVICE_ERROR, 'Serial device does not exist.');
        }
        $this->configureTty();
        $stream = @fopen($this->device, 'rb');
        if (!is_resource($stream)) {
            throw new SerialReadOnlyException(self::STAGE_DEVICE_ERROR, 'Unable to open serial device for reading.');
        }
        stream_set_blocking($stream, false);
        $this->stream = $stream;
    }

    private function configureTty(): void
    {
        $stty = is_executable('/usr/bin/stty') ? '/usr/bin/stty' : (is_executable('/bin/stty') ? '/bin/stty' : null);
        if ($stty === null) {
            throw new SerialReadOnlyException(self::STAGE_CONFIG_ERROR, 'stty is required for Linux serial configuration.');
        }
        $cmd = [$stty,'-F',$this->device,'raw','-echo',(string)$this->baud,'cs'.$this->dataBits,$this->stopBits === 2 ? 'cstopb' : '-cstopb','clocal'];
        array_push($cmd, ...match ($this->parity) {
            'none' => ['-parenb'], 'even' => ['parenb','-parodd'], 'odd' => ['parenb','parodd'],
        });
        array_push($cmd, ...match ($this->flowControl) {
            'none' => ['-crtscts','-ixon','-ixoff'],
            'hardware' => ['crtscts','-ixon','-ixoff'],
            'software' => ['-crtscts','ixon','ixoff'],
        });
        $job = ProcessRunner::start($cmd, '/', [], 5);
        $result = ProcessRunner::finish($job);
        if (!($result['ok'] ?? false) || (int)($result['code'] ?? 127) !== 0) {
            $detail = trim((string)($result['stderr'] ?? ''));
            throw new SerialReadOnlyException(self::STAGE_CONFIG_ERROR, 'Unable to configure serial tty with stty.' . ($detail !== '' ? ' ' . $detail : ''));
        }
    }

    /** @return array{frame:string,terminator:string}|null */
    private function extractFrame(bool $allowPrefixAtEnd): ?array
    {
        $bestPos = null; $bestName = null; $bestBytes = null;
        foreach ($this->terminators as $name => $bytes) {
            $pos = strpos($this->buffer, $bytes);
            if ($pos === false) continue;
            if (!$allowPrefixAtEnd && $pos + strlen($bytes) === strlen($this->buffer) && $this->isPrefixOfLongerTerminator($bytes)) {
                continue;
            }
            if ($bestPos === null || $pos < $bestPos || ($pos === $bestPos && strlen($bytes) > strlen((string)$bestBytes))) {
                $bestPos = $pos; $bestName = $name; $bestBytes = $bytes;
            }
        }
        if ($bestPos === null || $bestName === null || $bestBytes === null) return null;
        if ($bestPos > $this->maxFrameBytes) {
            throw new SerialReadOnlyException(self::STAGE_OVERFLOW, 'Serial frame exceeded maxFrameBytes before terminator.');
        }
        $frame = substr($this->buffer, 0, $bestPos);
        $this->buffer = substr($this->buffer, $bestPos + strlen($bestBytes));
        return ['frame'=>$frame,'terminator'=>$bestName];
    }

    private function isPrefixOfLongerTerminator(string $bytes): bool
    {
        foreach ($this->terminators as $candidate) {
            if (strlen($candidate) > strlen($bytes) && str_starts_with($candidate, $bytes)) return true;
        }
        return false;
    }

    private function assertNotOverflowing(): void
    {
        $longest = 0;
        foreach ($this->terminators as $bytes) $longest = max($longest, strlen($bytes));
        if (strlen($this->buffer) > $this->maxFrameBytes + $longest - 1) {
            throw new SerialReadOnlyException(self::STAGE_OVERFLOW, 'Serial frame exceeded maxFrameBytes without terminator.');
        }
    }

    /** @return array{frame:string,evidence:array<string,mixed>} */
    private function result(string $frame, string $terminator, int $started): array
    {
        return ['frame'=>$frame,'evidence'=>[
            'schema'=>'testkit.serial-readonly.v1','status'=>'PASS','mode'=>'readonly',
            'result'=>['frameReceived'=>true,'byteCount'=>strlen($frame),'terminator'=>$terminator,'durationMs'=>max(0,self::nowMs()-$started)],
            'readonlyInvariant'=>true,
        ]];
    }

    private static function nowMs(): int { return (int)round(microtime(true)*1000); }
}
