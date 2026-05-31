# Multi-root PHP backend discovery — V3 notes

Este archivo se agrega como nota segura para evitar reemplazar documentos largos existentes por versiones abreviadas.

La documentación principal que debe integrarse sin truncar es:

- README.md
- docs/CONTRATO.md
- docs/USO.md
- SUPPORT_MATRIX.md
- core/php/config/ConfigSchema.php

## Variables

```dotenv
TK_BACK_PHP_TEST_ROOTS=test,submodules/*/test,submodules/*/tests
TK_BACK_PHP_TEST_PATTERNS=*.test.php,*_smoke.php,*_integration.php
TK_BACK_PHP_TEST_EXCLUDE_ROOTS=
TK_BACK_PHP_TEST_EXCLUDE_PATTERNS=*/vendor/*,*/node_modules/*,*/_out/*,*/.testkit/*
```

Compatibilidad legacy:

```dotenv
TK_BACK_PHP_DIR=test/back
```

Si `TK_BACK_PHP_TEST_ROOTS` no está definido, `back-php` conserva el discovery legacy `test/back/**/*.test.php`.

## Convención recomendada

```txt
<Module>/test/smoke/
<Module>/test/integration/
<Module>/test/fixtures/
```

`submodules/*/tests` queda soportado como transición legacy, no como convención recomendada.
