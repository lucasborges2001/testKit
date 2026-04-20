# Doctor mode self-tests

Run:

```bash
php tests/framework/doctor_modes/run.php
```

What they validate:

- explicit `--full` render
- explicit `--compact` render
- CLI flag precedence over env default
- `--dump --full` structured output for doctor mode metadata
- basic parity between bash and PowerShell wrappers when `pwsh` is available

What they do **not** validate:

- Docker runtime
- bootstrap correctness
- real store connectivity
- runtime safety beyond wrapper-visible config
