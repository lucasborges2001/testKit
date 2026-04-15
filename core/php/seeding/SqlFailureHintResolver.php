<?php
declare(strict_types=1);

namespace Testkit\Core\Seeding;

final class SqlFailureHintResolver
{
    public static function hintForSqlFailure(string $stage, string $label, string $errorMessage): string
    {
        $normalized = strtolower($errorMessage);

        if (str_contains($normalized, 'unknown column') || str_contains($normalized, 'doesn\'t exist')) {
            return 'La sentencia referencia una columna u objeto inexistente. Revisá el orden entre schema/base/migrations y el baseline desde el que parte la DB.';
        }

        if (str_contains($normalized, 'duplicate') || str_contains($normalized, 'already exists')) {
            return 'El seed intenta crear algo que ya existe. Revisá idempotencia del SQL o residuos de una corrida previa al reset.';
        }

        if (str_contains($normalized, 'foreign key') || str_contains($normalized, 'constraint fails')) {
            return 'Hay una violación de integridad. Revisá orden de inserts, datos base requeridos y relaciones creadas en schema.';
        }

        if ($stage === 'schema') {
            return 'El fallo ocurrió en schema. Revisá DDL, compatibilidad con el engine y dependencia entre objetos creados.';
        }

        if ($stage === 'base') {
            return 'El fallo ocurrió en base. Revisá datos iniciales, columnas esperadas por el schema y dependencias entre inserts.';
        }

        if ($stage === 'migration') {
            return 'El fallo ocurrió dentro de una migración opcional o pendiente. Revisá el estado de partida del baseline y qué migraciones ya estaban absorbidas.';
        }

        if ($stage === 'validations') {
            return 'El fallo ocurrió en validations. Revisá que el estado final esperado del baseline coincida con schema/base/migrations aplicadas.';
        }

        if ($label !== '') {
            return 'Revisá la fase ' . $label . ' y el contrato estructural esperado antes de correr tests funcionales.';
        }

        return 'Revisá la sentencia SQL fallida, el estado real de la DB y el orden de ejecución del seed.';
    }

    /**
     * @return array<string,mixed>
     */
    public static function sqlErrorContext(\Throwable $error): array
    {
        $context = [];

        if ($error instanceof \PDOException) {
            $errorInfo = is_array($error->errorInfo ?? null) ? $error->errorInfo : [];
            $sqlState = trim((string)($errorInfo[0] ?? ''));
            $driverCode = trim((string)($errorInfo[1] ?? ''));
            $driverMessage = trim((string)($errorInfo[2] ?? ''));

            if ($sqlState !== '') {
                $context['sqlstate'] = $sqlState;
            }
            if ($driverCode !== '') {
                $context['driver_code'] = $driverCode;
            }
            if ($driverMessage !== '') {
                $context['driver_message'] = $driverMessage;
            }
        }

        $fallbackCode = trim((string)$error->getCode());
        if ($fallbackCode !== '' && !isset($context['sqlstate'])) {
            $context['sqlstate'] = $fallbackCode;
        }

        return $context;
    }

    public static function statementExcerpt(string $statement, int $maxLen = 220): string
    {
        $statement = trim((string)preg_replace('/\s+/', ' ', trim($statement)));
        if ($statement === '') {
            return '';
        }

        if (mb_strlen($statement) <= $maxLen) {
            return $statement;
        }

        return mb_substr($statement, 0, $maxLen - 3) . '...';
    }
}
