# 01 — Quickstart

## Linux/macOS (bash)

```bash
cp test/.env.test.example test/.env.test
cd test
./bin/testkit doctor
./bin/testkit up -d
./scripts/seed.sh
./bin/testkit run --rm testkit php runTest.php
```

## Windows (PowerShell)

```powershell
Copy-Item test\.env.test.example test\.env.test
cd test
.\bin\testkit.ps1 doctor
.\bin\testkit.ps1 up -d
.\scripts\seed.sh
.\bin\testkit.ps1 run --rm testkit php runTest.php
```

## Postgres (opcional)

```bash
cd test
./bin/testkit --pg up -d
./scripts/seed.sh
```

> Nota: Postgres solo corre si lo activás con `--pg`.
