# Fase 3.1.2 — Entrypoints operativos tipados

## Estado

Implementado en `testKit`.

Este corte migra Makefile y los wrappers de `migration-contract` a la CLI tipada. El workflow y la UI PowerShell quedan separados para el siguiente commit porque tienen matrices de validación propias.

## Baseline

```text
Repositorio: lucasborges2001/testKit
Rama base: main
Commit base auditado: b5b09284c69728dfa93266300f405e3e57157684
Rama de trabajo: agent/testkit-contract-normalization
Commit anterior: abcaa2718f90f493797e24c16bc7f91094f0c7d7
Fecha: 2026-07-28
```

## Cambios

- `Makefile` usa `--group`, `--suite` y `--category`.
- Se elimina el target Make `test-back-py`; el nombre canónico es `test-back-python`.
- `migration_contract.sh` y `.ps1` usan `--suite migration-contract`.
- Los wrappers desde backup usan la misma suite canónica.
- Los wrappers PowerShell propagan `$LASTEXITCODE`.
- Argumentos inválidos del wrapper Bash desde backup devuelven `2`.

## Contrato resultante

```bash
make test
make test-back
make test-back-python
make test-front
make test-smoke
./scripts/migration_contract.sh
./scripts/migration_contract_from_backup.sh report /ruta/reporte.json
```

Equivalentes efectivos:

```text
--group all
--group back
--suite back-python
--group front
--category smoke
--suite migration-contract
```

## Invariantes

1. no se emiten targets posicionales;
2. no se reintroducen aliases;
3. cada suite concreta usa `--suite`;
4. agregaciones usan `--group`;
5. filtros semánticos usan `--category`;
6. no se modifica store, stack o coverage;
7. no se modifican consumidores externos.

## Validación

```bash
bash -n scripts/migration_contract.sh
bash -n scripts/migration_contract_from_backup.sh
make -n test test-back test-back-python test-front test-smoke test-perf test-stress

grep -RInE 'runTest\.php[[:space:]]+(all|back|back-py|front|smoke|perf|stress|migration-contract)([[:space:]]|$)' \
  Makefile scripts/migration_contract*.sh scripts/migration_contract*.ps1
```

PowerShell pendiente de CI Windows:

```powershell
pwsh -NoProfile -NonInteractive -Command {
  [System.Management.Automation.Language.Parser]::ParseFile('scripts/migration_contract.ps1', [ref]$null, [ref]$errors) | Out-Null
  if ($errors.Count) { exit 1 }
}
```

## Criterio PASS

- Makefile no contiene targets posicionales;
- wrappers de migración contienen `--suite migration-contract`;
- Bash parsea;
- PowerShell parsea en CI Windows;
- no se modifica workflow ni UI en este corte.

## Rollback

Revertir este commit restaura los entrypoints anteriores. El parser estricto y los comandos internos tipados permanecen sin cambios.

## No verificado

- ejecución real de Docker/MySQL;
- PowerShell local;
- CI remoto;
- consumidores externos;
- integración mediante `Base`.

## Siguiente commit

Fase 3.1.3 — cutover de CI y UI PowerShell:

- `.github/workflows/ci.yml`;
- `ui/powershell/lib/Testkit.UI.Plan.ps1`;
- tests PowerShell de UI;
- ejemplos operativos ejecutables restantes.
