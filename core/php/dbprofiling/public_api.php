<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

\Testkit\Core\DbProfiling\QueryProfileCollector::markBootstrapped();

if (!function_exists('tk_profiled_pdo')) {
    /**
     * @param array<mixed,mixed> $options
     * @param array<string,mixed> $context
     */
    function tk_profiled_pdo(
        string $dsn,
        ?string $username = null,
        ?string $password = null,
        array $options = [],
        array $context = []
    ): PDO {
        return new \Testkit\Core\DbProfiling\ProfiledPDO($dsn, $username, $password, $options, $context);
    }
}

if (!function_exists('tk_mysql_profile_enable_pdo')) {
    /**
     * Only statements prepared after this call can be observed.
     * Direct query()/exec() on this existing PDO remain outside automatic capture.
     *
     * @param array<string,mixed> $context
     */
    function tk_mysql_profile_enable_pdo(PDO $pdo, array $context = []): PDO
    {
        if (!\Testkit\Core\DbProfiling\QueryProfileCollector::isEnabled()) {
            return $pdo;
        }

        $connectionId = \Testkit\Core\DbProfiling\ConnectionRegistry::register(
            $pdo,
            'existing_pdo',
            'mysql',
            [
                'query' => false,
                'exec' => false,
                'prepare_execute' => true,
                'transactions' => false,
            ],
            true
        );
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [
            \Testkit\Core\DbProfiling\ProfiledPDOStatement::class,
            [$connectionId, \Testkit\Core\DbProfiling\MysqlCaptureMethod::EXISTING_PDO_STATEMENT_EXECUTE],
        ]);
        \Testkit\Core\DbProfiling\QueryProfileCollector::addFinding(
            'existing_pdo_partial_capture',
            'info',
            'Un PDO existente fue instrumentado parcialmente: solo execute() de statements preparados después del helper.',
            array_merge($context, ['connection_id' => $connectionId]),
            'Migrar la factory a tk_profiled_pdo() para observar también query() y exec().'
        );
        return $pdo;
    }
}

if (!function_exists('tk_mysql_profile_record')) {
    /** @param array<string,mixed> $context */
    function tk_mysql_profile_record(
        string $sql,
        float $durationMs,
        string $source = '',
        string $caller = '',
        array $context = []
    ): void {
        $context['capture_method'] ??= \Testkit\Core\DbProfiling\MysqlCaptureMethod::MANUAL_RECORD;
        \Testkit\Core\DbProfiling\QueryProfileCollector::record($sql, $durationMs, $source, $caller, $context);
    }
}

if (!function_exists('tk_mysql_profile_register_connection')) {
    /**
     * @param array<string,bool> $capabilities
     */
    function tk_mysql_profile_register_connection(
        object $connection,
        string $adapter,
        string $engine = 'mysql',
        array $capabilities = [],
        bool $instrumented = true
    ): string {
        return \Testkit\Core\DbProfiling\ConnectionRegistry::register(
            $connection,
            $adapter,
            $engine,
            $capabilities,
            $instrumented
        );
    }
}

if (!function_exists('tk_mysql_profile_explainable')) {
    function tk_mysql_profile_explainable(string $sql): bool
    {
        return \Testkit\Core\DbProfiling\MysqlExplainAnalyzer::isExplainableSql($sql);
    }
}

if (!function_exists('tk_mysql_profile_mysqli_record_query')) {
    /** @param array<string,mixed> $context */
    function tk_mysql_profile_mysqli_record_query(
        string $sql,
        float $durationMs,
        string $source = '',
        string $caller = '',
        array $context = []
    ): void {
        $context['capture_method'] = \Testkit\Core\DbProfiling\MysqlCaptureMethod::MYSQLI_QUERY_MANUAL;
        \Testkit\Core\DbProfiling\QueryProfileCollector::record(
            $sql,
            $durationMs,
            $source,
            $caller,
            $context
        );
    }
}

if (!function_exists('tk_mysql_profile_mysqli_remember')) {
    /** @param array<string,mixed> $context */
    function tk_mysql_profile_mysqli_remember(object $statement, string $sql, array $context = []): void
    {
        if (!\Testkit\Core\DbProfiling\QueryProfileCollector::isEnabled()) {
            return;
        }
        $GLOBALS['__tk_mysql_profile_mysqli_sql'][spl_object_id($statement)] = [
            'sql' => $sql,
            'context' => \Testkit\Core\DbProfiling\InstrumentationContext::sanitizeMap($context),
        ];
    }
}

if (!function_exists('tk_mysql_profile_mysqli_forget')) {
    function tk_mysql_profile_mysqli_forget(object $statement): void
    {
        unset($GLOBALS['__tk_mysql_profile_mysqli_sql'][spl_object_id($statement)]);
    }
}

if (!function_exists('tk_mysql_profile_mysqli_record_execute')) {
    /** @param array<string,mixed> $context */
    function tk_mysql_profile_mysqli_record_execute(
        object $statement,
        float $durationMs,
        string $fallbackSql = '',
        array $context = []
    ): void {
        if (!\Testkit\Core\DbProfiling\QueryProfileCollector::isEnabled()) {
            return;
        }
        $remembered = $GLOBALS['__tk_mysql_profile_mysqli_sql'][spl_object_id($statement)] ?? [];
        $sql = is_array($remembered) ? (string)($remembered['sql'] ?? $fallbackSql) : (string)$remembered;
        if ($sql === '') {
            \Testkit\Core\DbProfiling\QueryProfileCollector::addFinding(
                'mysqli_statement_sql_missing',
                'watch',
                'No se pudo asociar SQL a un mysqli statement ejecutado.',
                [],
                'Llamar tk_mysql_profile_mysqli_remember() durante prepare().'
            );
            return;
        }
        $rememberedContext = is_array($remembered) && is_array($remembered['context'] ?? null)
            ? $remembered['context']
            : [];
        $context = array_merge($rememberedContext, $context, [
            'capture_method' => \Testkit\Core\DbProfiling\MysqlCaptureMethod::MYSQLI_STATEMENT_EXECUTE_MANUAL,
        ]);
        \Testkit\Core\DbProfiling\QueryProfileCollector::record(
            $sql,
            $durationMs,
            \Testkit\Core\DbProfiling\QueryProfileCollector::inferSource(),
            \Testkit\Core\DbProfiling\QueryProfileCollector::inferCaller(),
            $context
        );
    }
}
