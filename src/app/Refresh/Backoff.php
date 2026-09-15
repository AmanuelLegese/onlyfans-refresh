<?php

namespace App\Refresh;

class Backoff
{
    public static function fullJitter(int $attempt, int $base = 0, int $cap = 0): int
    {
        $base = $base ?: config('refresh.backoff_base', 1);
        $cap = $cap ?: config('refresh.backoff_cap', 8);

        $exponential = min($base * (2 ** ($attempt - 1)), $cap);

        return (int) random_int(0, $exponential);
    }
}
