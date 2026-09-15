<?php

namespace App\Jobs\Middleware;

use Closure;
use Illuminate\Support\Facades\Redis;

class AccountCooldown
{
    public function handle(object $job, Closure $next): void
    {
        $profile = $job->getProfile();
        $account = $profile->account;

        $key = "refresh:cooldown:account:{$account->id}";
        $cooldownUntil = Redis::get($key);

        if ($cooldownUntil && now()->timestamp < (int) $cooldownUntil) {
            $delay = random_int(1, 3);
            $job->logEvent('refresh.deferred', ['reason' => 'account_cooldown', 'delay_seconds' => $delay]);
            $job->release($delay);

            return;
        }

        $next($job);
    }

    public static function setCooldown(int $accountId, int $attempt): void
    {
        $key = "refresh:cooldown:account:{$accountId}";
        $base = 2;
        $cap = 30;
        $delay = min($base * (2 ** $attempt), $cap);
        $delayWithJitter = random_int($delay, $delay + 2);

        Redis::set($key, now()->addSeconds($delayWithJitter)->timestamp, 'EX', 60);
    }

    public static function clearCooldown(int $accountId): void
    {
        Redis::del("refresh:cooldown:account:{$accountId}");
    }
}
