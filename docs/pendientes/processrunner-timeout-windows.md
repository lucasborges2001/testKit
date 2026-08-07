# Pendiente — ProcessRunner timeout en Windows

## Contexto y evidencia

El run de GitHub Actions `31196294460`, ejecutado sobre Windows Server 2025, demostró que la superficie PowerShell de TestKit pasa completamente:

```text
PowerShell self-tests
10 passed, 0 failed
```

Después, al ejecutar la suite PHP completa de framework dentro del mismo job, `tests/framework/test_process_timeout.php` reprodujo un fallo nativo de terminación de procesos:

```text
[FAIL] ProcessRunner timeout
FAIL: wall time 60.162622928619s reached the sleep duration — process was not killed
```

El job agotó luego su `timeout-minutes: 15`.

Código implicado:

```text
core/php/execution/ProcessRunner.php
tests/framework/test_process_timeout.php
```

`ProcessRunner::terminateJob()` usa `proc_terminate(..., 15)` seguido de `proc_terminate(..., 9)`. Ese contrato está expresado en términos POSIX y no existe evidencia de que termine correctamente el proceso o su árbol en Windows.

## Clasificación

- destino: `testKit` core;
- tipo: portabilidad / ejecución de procesos;
- prioridad: media;
- bloquea I2 store explícito: **NO**;
- bloquea afirmar soporte nativo completo de `ProcessRunner` en Windows: **SÍ**.

## Objetivo

Definir e implementar una estrategia de timeout/terminación de procesos que funcione de forma verificable en Windows sin degradar Linux.

El resultado debe mantener las invariantes públicas actuales:

- `timeout=true` cuando vence el límite;
- exit code no exitoso, con `124` cuando corresponda;
- stderr contiene `[testkit] TIMEOUT`;
- el proceso no continúa hasta completar su duración original;
- no quedan procesos hijos huérfanos.

## Dependencias y decisiones

Antes de implementar:

1. verificar semántica real de `proc_open`, `proc_get_status`, `proc_terminate` y PID en PHP 8.4 sobre Windows;
2. determinar si alcanza con terminar el PID o si se requiere terminar árbol de procesos;
3. evaluar una estrategia Windows explícita (`taskkill /PID <pid> /T /F` u otra API equivalente) sin introducir dependencias externas innecesarias;
4. conservar la ruta POSIX actual si sigue siendo correcta en Linux;
5. evitar inferir éxito solo porque `proc_terminate()` devuelve truthy.

## Criterio de aceptación

PASS únicamente si existen pruebas reales separadas en Linux y Windows que demuestren:

```text
timeout configurado: 2 s
proceso objetivo: > 10 s
wall time: claramente menor que la duración objetivo
timeout flag: true
exit code: != 0
TIMEOUT marker: presente
proceso/árbol: terminado
```

La prueba Windows no debe depender del comando Unix `sleep`; debe usar un proceso disponible de forma determinista en el runner Windows.

## Validación mínima

Linux:

```bash
php tests/framework/test_process_timeout.php
php tests/framework/run.php
```

Windows, después de agregar un fixture determinista:

```powershell
php tests/framework/test_process_timeout.php
```

Además ejecutar el workflow completo y exigir que cualquier futuro gate de `ProcessRunner` Windows termine antes del timeout global del job.

## Rollback

Si la implementación Windows introduce regresiones, revertir únicamente la estrategia específica de terminación y conservar este pendiente abierto. No degradar el comportamiento Linux para forzar paridad artificial.

## No verificado

- mecanismo correcto de terminación de árbol en PHP 8.4/Windows;
- comportamiento con procesos que crean nietos;
- equivalencia de exit codes entre Windows y POSIX;
- comportamiento fuera del runner GitHub `windows-2025`.
