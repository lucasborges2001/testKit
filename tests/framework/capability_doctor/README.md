# Capability doctor self-tests

Run:

```bash
php tests/framework/capability_doctor/run.php
```

What they validate:

- status global del capability doctor
- presencia o ausencia de codes semánticos
- advisory exit semantics (`Doctor: OK` aunque capability marque `FAIL`)
- dump estructurado (`TESTKIT_CAPABILITY_CHECK_<n>_*`)
- paridad básica entre wrapper bash y PowerShell cuando `pwsh` está disponible

What they do **not** validate:

- spacing cosmético
- ejecución real de Docker
- seguridad runtime más allá de la config visible del wrapper
