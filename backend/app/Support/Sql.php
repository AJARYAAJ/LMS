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
