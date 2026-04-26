<?php
declare(strict_types=1);

require_once __DIR__ . '/auto_prepend.php';

$tkRoot = (string)(getenv('TESTKIT_ROOT') ?: dirname(__DIR__, 2));
require_once rtrim($tkRoot, '/\\') . '/core/php/dbprofiling/bootstrap.php';

\Testkit\Core\DbProfiling\QueryProfileCollector::registerShutdown();

if (!function_exists('tk_profiled_pdo')) {
    /**
     * Opt-in PDO constructor that records query, exec and prepared-statement execute timings when TESTKIT_DB_PROFILE=1.
     *
     * @param array<mixed,mixed> $options
     */
    function tk_profiled_pdo(string $dsn, ?string $username = null, ?string $password = null, array $options = []): PDO
    {
        return new \Testkit\Core\DbProfiling\ProfiledPDO($dsn, $username, $password, $options);
    }
}

if (!function_exists('tk_mysql_profile_enable_pdo')) {
    /**
     * Enables prepared-statement execute profiling on an existing PDO instance.
     * It cannot intercept direct PDO::query() / PDO::exec() calls already made through that instance.
     */
    function tk_mysql_profile_enable_pdo(PDO $pdo): PDO
    {
        if (\Testkit\Core\DbProfiling\QueryProfileCollector::isEnabled()) {
            $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [\Testkit\Core\DbProfiling\ProfiledPDOStatement::class, []]);
        }
        return $pdo;
    }
}

if (!function_exists('tk_mysql_profile_record')) {
    function tk_mysql_profile_record(string $sql, float $durationMs, string $source = '', string $caller = ''): void
    {
        \Testkit\Core\DbProfiling\QueryProfileCollector::record($sql, $durationMs, $source, $caller);
    }
}

if (!function_exists('tk_mysql_profile_mysqli_remember')) {
    /**
     * Associate a mysqli_stmt-like object with the SQL used to prepare it.
     * This is intentionally object-based and untyped so the helper remains loadable
     * in environments where the mysqli extension is absent.
     */
    function tk_mysql_profile_mysqli_remember(object $statement, string $sql): void
    {
        if (!\Testkit\Core\DbProfiling\QueryProfileCollector::isEnabled()) {
            return;
        }
        $GLOBALS['__tk_mysql_profile_mysqli_sql'][spl_object_id($statement)] = $sql;
    }
}

if (!function_exists('tk_mysql_profile_mysqli_forget')) {
    function tk_mysql_profile_mysqli_forget(object $statement): void
    {
        unset($GLOBALS['__tk_mysql_profile_mysqli_sql'][spl_object_id($statement)]);
    }
}

if (!function_exists('tk_mysql_profile_mysqli_record_execute')) {
    /**
     * Record a mysqli prepared-statement execution using the SQL remembered at prepare() time.
     */
    function tk_mysql_profile_mysqli_record_execute(object $statement, float $durationMs, string $fallbackSql = ''): void
    {
        if (!\Testkit\Core\DbProfiling\QueryProfileCollector::isEnabled()) {
            return;
        }
        $sql = (string)($GLOBALS['__tk_mysql_profile_mysqli_sql'][spl_object_id($statement)] ?? $fallbackSql);
        if ($sql === '') {
            return;
        }
        \Testkit\Core\DbProfiling\QueryProfileCollector::record(
            $sql,
            $durationMs,
            \Testkit\Core\DbProfiling\QueryProfileCollector::inferSource(),
            \Testkit\Core\DbProfiling\QueryProfileCollector::inferCaller()
        );
    }
}
