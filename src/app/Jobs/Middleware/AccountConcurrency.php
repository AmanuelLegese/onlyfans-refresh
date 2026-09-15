<?php

namespace App\Jobs\Middleware;

use Closure;
use Illuminate\Support\Facades\Redis;

class AccountConcurrency
{
    public function handle(object $job, Closure $next): void
    {
        $profile = $job->getProfile();
        $account = $profile->account;
        $limit = $account->max_concurrency;

        $key = "refresh:concurrency:account:{$account->id}";
        $current = (int) Redis::get($key);

        if ($current >= $limit) {
            $delay = random_int(2, 5);
            $job->release($delay);
            return;
        }

        Redis::incr($key);
        Redis::expire($key, 60);

        try {
            $next($job);
        } finally {
            Redis::decr($key);
        }
    }
}
