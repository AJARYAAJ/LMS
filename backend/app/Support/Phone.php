<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/** Phone matching that ignores formatting (spaces, dashes, brackets, dots). */
class Phone
{
    public static function digits(?string $phone): string
    {
        return preg_replace('/\D+/', '', (string) $phone);
    }

    /** Match on the last 8 digits so "+91 98765 43210" and "9876543210" are the same number. */
    public static function whereMatches(Builder $query, string $digits, string $column = 'phone', string $boolean = 'and'): Builder
    {
        $normalized = "replace(replace(replace(replace(replace(replace({$column}, ' ', ''), '-', ''), '(', ''), ')', ''), '.', ''), '+', '')";

        return $query->whereRaw("{$normalized} like ?", ['%'.substr($digits, -8)], $boolean);
    }
}
