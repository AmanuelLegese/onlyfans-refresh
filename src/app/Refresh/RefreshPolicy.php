<?php

namespace App\Refresh;

class RefreshPolicy
{
    public static function intervalFor(int $likes): int
    {
        if ($likes > 100_000) {
            return config('refresh.interval_high_likes', 24 * 3600);
        }

        return config('refresh.interval_low_likes', 72 * 3600);
    }

    public static function nextRefreshAt(int $likes): \Illuminate\Support\Carbon
    {
        $interval = self::intervalFor($likes);
        return now()->addSeconds($interval);
    }
}
