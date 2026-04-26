<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

if (!function_exists('tk_influx_profile_record')) {
    function tk_influx_profile_record(string $query, float $durationMs, string $dialect = 'flux', string $source = '', string $caller = ''): void
    {
        \Testkit\Core\InfluxProfiling\InfluxProfileCollector::record($query, $durationMs, $dialect, $source, $caller);
    }
}

if (!function_exists('tk_influx_profile_enabled')) {
    function tk_influx_profile_enabled(): bool
    {
        return \Testkit\Core\InfluxProfiling\InfluxProfileCollector::isEnabled();
    }
}

if (!function_exists('tk_influx_profile_wrap')) {
    /**
     * Execute a callable and record its duration as an Influx query profile sample.
     * The original return value and exceptions are preserved.
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    function tk_influx_profile_wrap(callable $fn, string $query, string $dialect = 'flux', string $source = ''): mixed
    {
        $started = microtime(true);
        try {
            return $fn();
        } finally {
            \Testkit\Core\InfluxProfiling\InfluxProfileCollector::record(
                $query,
                (microtime(true) - $started) * 1000.0,
                $dialect,
                $source !== '' ? $source : \Testkit\Core\InfluxProfiling\InfluxProfileCollector::inferSource(),
                \Testkit\Core\InfluxProfiling\InfluxProfileCollector::inferCaller()
            );
        }
    }
}

if (!function_exists('tk_profiled_influx_query')) {
    /**
     * Lightweight compatibility wrapper for clients where the caller supplies the execution closure.
     * The $client argument is intentionally opaque; this helper does not assume an Influx client API.
     *
     * @template T
     * @param mixed $client
     * @param callable(mixed,string):T $executor
     * @return T
     */
    function tk_profiled_influx_query(mixed $client, string $query, callable $executor, string $dialect = 'flux'): mixed
    {
        return tk_influx_profile_wrap(
            static fn(): mixed => $executor($client, $query),
            $query,
            $dialect,
            \Testkit\Core\InfluxProfiling\InfluxProfileCollector::inferSource()
        );
    }
}
