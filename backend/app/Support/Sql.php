<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Small portability helpers for aggregate queries (SQLite / PostgreSQL / MySQL).
 */
class Sql
{
    /** Expression for the calendar date of a timestamp column. */
    public static function date(string $column): string
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql' => "({$column})::date",
            default => "date({$column})",
        };
    }

    /** Expression for the hours between two timestamp columns (null when either is null). */
    public static function hoursBetween(string $from, string $to): string
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql' => "extract(epoch from ({$to} - {$from})) / 3600.0",
            'mysql', 'mariadb' => "timestampdiff(second, {$from}, {$to}) / 3600.0",
            default => "(julianday({$to}) - julianday({$from})) * 24.0",
        };
    }

    /** Expression for the age of a timestamp column in (fractional) days. */
    public static function ageInDays(string $column): string
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql' => "extract(epoch from (now() - {$column})) / 86400.0",
            'mysql', 'mariadb' => "timestampdiff(second, {$column}, now()) / 86400.0",
            default => "julianday('now') - julianday({$column})",
        };
    }
}
