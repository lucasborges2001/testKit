# Serial passive/read-only capture

## Scope

TestKit provides a Linux reference capability for local serial frame capture without transmitting application bytes to the attached device.

Closed path:

```text
local tty / PTY
-> SerialReadOnlyClient
-> bounded frame capture
-> neutral structural evidence
-> in-memory frame for host assertions
```

TestKit owns serial transport mechanics. Host projects own barcode grammar, credentials, business meaning and expected payloads.

## Public API

Load:

```php
require_once $testkitRoot . '/core/php/serial/bootstrap.php';
```

Public classes:

```text
Testkit\Core\Serial\SerialReadOnlyClient
Testkit\Core\Serial\SerialReadOnlyException
```

Construction is explicit:

```php
$client = new SerialReadOnlyClient(
    device: '/dev/ttyUSB0',
    baud: 9600,
    dataBits: 8,
    stopBits: 1,
    parity: 'none',
    flowControl: 'none',
    timeoutMs: 3000,
    maxFrameBytes: 256,
    terminators: ['CRLF', 'CR', 'LF'],
);
$result = $client->captureFrame();
```

The device example is illustrative. TestKit does not define a default physical tty.

## Configuration contract

Supported settings:

```text
device: absolute Linux path
baud: 50,75,110,134,150,200,300,600,1200,1800,2400,4800,
      9600,19200,38400,57600,115200,230400
dataBits: 5|6|7|8
stopBits: 1|2
parity: none|even|odd
flowControl: none|hardware|software
timeoutMs: 1..60000
maxFrameBytes: 1..1048576
terminators: one or more of CR, LF, CRLF
```

Invalid configuration fails with `SerialReadOnlyException::stage() === 'config_error'`.

## Linux reference implementation

The client uses `stty` through TestKit's existing `ProcessRunner` with an argv array, not shell-concatenated input. `stty` configures only the local tty parameters. The client then opens the tty in read-binary mode and uses non-blocking streams plus `stream_select()`.

`stty` must exist at `/usr/bin/stty` or `/bin/stty`. Missing `stty` is `config_error`.

The implementation is Linux-specific in this phase. Windows serial devices are not claimed as supported by this contract.

## Passive invariant

The production client has no public or private payload-transmit path:

```text
no write()
no send()
no transmit()
no command()
no trigger()
no configureScanner()
no fwrite() in core/php/serial
```

Allowed operations are limited to:

```text
configure the local tty
open the local tty
read bytes
wait for a terminator
close the tty
```

Configuring baud/parity/flow control through tty ioctls is not a scanner command and emits no application payload bytes.

## Frame semantics

`captureFrame()` returns one completed frame. Terminator bytes are removed from the returned frame.

Handled conditions:

```text
FRAME         completed CR, LF or CRLF frame
TIMEOUT       no complete terminator before timeout
OVERFLOW      payload exceeds maxFrameBytes
DEVICE_ERROR  tty missing, unreadable, closed or stream failure
CONFIG_ERROR  invalid serial configuration or stty failure
```

Technical failures are represented by `SerialReadOnlyException::stage()` values:

```text
timeout
overflow
device_error
config_error
```

CRLF is preferred over CR when both begin at the same byte. Consecutive frames are retained in the client's internal receive buffer and can be consumed by successive `captureFrame()` calls.

A partial frame is never returned as PASS merely because the timeout elapsed.

## Evidence and payload security

Successful capture returns:

```text
result.frame     raw frame, in-memory only for the host consumer
result.evidence  neutral metadata safe for persistence
```

Evidence schema:

```json
{
  "schema": "testkit.serial-readonly.v1",
  "status": "PASS",
  "mode": "readonly",
  "result": {
    "frameReceived": true,
    "byteCount": 15,
    "terminator": "CRLF",
    "durationMs": 42
  },
  "readonlyInvariant": true
}
```

The neutral evidence never contains the frame payload. A host that needs persistent correlation should prefer a hash, length and host-owned classification rather than raw credentials.

## Docker device pass-through

`bin/testkit` forwards normal `docker compose run` options. A host runner can therefore expose exactly one serial device:

```bash
./bin/testkit run --rm --no-deps \
  --device /dev/ttyUSB0:/dev/testkit-serial:rw \
  -e HOST_SERIAL_DEVICE=/dev/testkit-serial \
  testkit php runTest.php --suite infra-php --test test/infra/example.test.php
```

`rw` is needed for tty configuration/ioctl compatibility; it does not add a payload-write API to `SerialReadOnlyClient`. Do not use `--privileged`, do not mount all of `/dev`, and do not expose unrelated devices. `m` permission is intentionally omitted.

Host projects may resolve a stable symlink such as `/dev/serial/by-id/...` before mapping it into the container.

## PTY self-test

Framework focal test:

```bash
php tests/framework/test_serial_readonly.php
```

It uses a Python PTY fixture and covers:

```text
PASS CR
PASS LF
PASS CRLF
PASS consecutive frames
FAIL timeout
FAIL overflow
FAIL missing device
FAIL invalid baud/dataBits/stopBits/parity/flowControl
PASS no public TX-like API
PASS no fwrite in the production serial client
PASS neutral evidence does not contain payload
```

The PTY writer is test-only infrastructure. Its write operation is not part of the production serial client.

A PTY PASS is not physical scanner hardware evidence.

## Ownership boundary

### TestKit owns

```text
local tty configuration
bounded passive reads
timeout/overflow/device errors
terminator handling
neutral evidence
PTY fixture coverage
```

### Host project owns

```text
which physical device to expose
serial settings for that device
payload grammar
expected payload/hash
credential redaction
business assertions
hardware acceptance state
```

TestKit does not know scanner brands, barcode formats, PINs, parcel indexes or Locker semantics.
