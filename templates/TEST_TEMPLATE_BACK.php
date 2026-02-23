<?php
/**
 * TEST: <módulo> / <caso>
 * SCOPE: unit|integration
 * QUÉ PRUEBA:
 *   - <criterio verificable 1>
 *   - <criterio verificable 2>
 * DEPENDE DE:
 *   - <DB mysql_test | endpoint | archivo>
 * DATOS:
 *   - seeds: <001_schema.sql, 010_seed.sql>
 */

declare(strict_types=1);

require_once __DIR__ . '/../utils/php/testkit.php';

use TestKit\Assert;

// Arrange

// Act

// Assert
Assert::true(true, 'template');
