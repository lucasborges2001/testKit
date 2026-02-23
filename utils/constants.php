<?php
/**
 * test/utils/constants.php
 *
 * Constantes globales útiles para tests y runners.
 */

// Defaults
if (!defined('TEST_SCOPE_DEFAULT')) define('TEST_SCOPE_DEFAULT', 'all');
if (!defined('TEST_FAIL_FAST_DEFAULT')) define('TEST_FAIL_FAST_DEFAULT', true);
if (!defined('TEST_DEFAULT_TIMEOUT_MS')) define('TEST_DEFAULT_TIMEOUT_MS', 5000);

// Runner exit codes (contrato)
// 0 = PASS (todo ok)
// 1 = FAIL (al menos un test falló)
// 2 = SKIP (no se ejecutó nada / todo skip)  [opcional]
// 3 = ERROR (error del runner / mala config / crash)
if (!defined('PVT_EXIT_PASS'))  define('PVT_EXIT_PASS', 0);
if (!defined('PVT_EXIT_FAIL'))  define('PVT_EXIT_FAIL', 1);
if (!defined('PVT_EXIT_SKIP'))  define('PVT_EXIT_SKIP', 2);
if (!defined('PVT_EXIT_ERROR')) define('PVT_EXIT_ERROR', 3);
