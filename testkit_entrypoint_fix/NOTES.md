# Entry-point fix for TestKit wrappers

What changed:
- `bin/testkit`
- `bin/testkit.ps1`

Both wrappers now rewrite common TestKit entrypoints when you run them from the project root
inside the `testkit` service.

Examples that now work again:

```bash
./bin/testkit run --rm testkit php runTest.php back
./bin/testkit run --rm testkit php scripts/report.php
```

PowerShell:

```powershell
.\bin\testkit.ps1 run --rm testkit php runTest.php back
.\bin\testkit.ps1 run --rm testkit php scripts/report.php
```

Internally they are rewritten to `/workspace/testkit/...`, because the container runs from
`/workspace/project` but the TestKit entrypoints live under `/workspace/testkit`.
