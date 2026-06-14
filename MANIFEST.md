# testKit cleanup package

Archivos incluidos:

- `bin/testkit` — wrapper Bash completo modificado para despachar `cleanup`.
- `bin/testkit.ps1` — wrapper PowerShell completo modificado para despachar `cleanup`.
- `scripts/cleanup.php` — entrypoint PHP nuevo.
- `core/php/cleanup/CleanupCommand.php` — implementación nueva del comando.
- `docs/CLEANUP.md` — documentación de uso y seguridad.

Validaciones realizadas en este paquete:

- `bash -n bin/testkit`
- `php -l scripts/cleanup.php`
- `php -l core/php/cleanup/CleanupCommand.php`

No incluye patch. Son archivos completos para copiar sobre el repo.
