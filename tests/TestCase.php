<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    /**
     * Register the MySQL functions the dashboard/history SQL uses as SQLite
     * functions with identical semantics, so the production statements run
     * unmodified on the isolated in-memory SQLite test connection:
     *
     *  - CURDATE()          -> today's date on the DB clock
     *  - ADDDATE(d, n)      -> date plus n days
     *  - SUBDATE(d, n)      -> date/datetime minus n days (the port's
     *                           plain-function spelling of
     *                           DATE_SUB(d, INTERVAL n DAY) / 24 HOUR where n=1)
     *  - NOW()              -> current datetime
     *  - CONCAT(...)        -> NULL if any argument is NULL, else concatenation
     *
     * The same technique as PatientTest's local concat() shim (kept there,
     * re-registration is harmless). No-op on non-SQLite connections.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        DB::getPdo()->sqliteCreateFunction('curdate', fn (): string => date('Y-m-d'));

        DB::getPdo()->sqliteCreateFunction(
            'adddate',
            fn ($date, $days): string => date('Y-m-d', strtotime($date.' +'.(int) $days.' days')),
            2,
        );

        DB::getPdo()->sqliteCreateFunction(
            'subdate',
            fn ($date, $days): string => date(
                str_contains((string) $date, ':') ? 'Y-m-d H:i:s' : 'Y-m-d',
                strtotime($date) - ((int) $days * 86400),
            ),
            2,
        );

        DB::getPdo()->sqliteCreateFunction('now', fn (): string => date('Y-m-d H:i:s'));

        // MySQL CONCAT(): NULL if ANY argument is NULL, else concatenation.
        DB::getPdo()->sqliteCreateFunction('concat', function (...$args): ?string {
            foreach ($args as $arg) {
                if ($arg === null) {
                    return null;
                }
            }

            return implode('', $args);
        });
    }
}
