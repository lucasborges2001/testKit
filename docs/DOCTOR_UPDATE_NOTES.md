# doctor update notes

## Qué cambia

- `bin/testkit` y `bin/testkit.ps1` dejan de ser wrappers tontos que delegan todo en un controller.
- Ambos entrypoints ahora hacen el dispatch real de `doctor`, `inspect` y runtime.
- `doctor` queda partido en submódulos por responsabilidad:
  - parse/state/shared
  - base checks
  - capability checks
  - render/dump

## Modos

### Full
```bash
./bin/testkit doctor --full
./bin/testkit doctor migration-contract --full
```

### Compact
```bash
./bin/testkit doctor --compact
./bin/testkit doctor migration-contract --compact
```

También soporta env fallback:

```bash
TESTKIT_DOCTOR_MODE=compact ./bin/testkit doctor
```

## Compatibilidad preservada

- `doctor --dump`
- `doctor migration-contract`
- `doctor --dump migration-contract`

## Decisión de diseño

- **default = full**
- razón: no romper la expectativa documental previa del repo ni los self-tests existentes de capability doctor.

## Limpieza opcional

Estos archivos pueden quedar obsoletos si el repo migra del todo al nuevo entrypoint-controller:

- `lib/bash/controller.sh`
- `lib/powershell/Controller.ps1`

No los borré en este zip porque el zip trae solo archivos nuevos/modificados.
