<?php

namespace App\Jobs\Middleware;

use Closure;
use Illuminate\Support\Facades\Redis;

/**
 * At most `max_concurrency` jobs per account run at once, so one busy account cannot take every
 * worker. Redis::funnel acquires a slot atomically; a slot held by a killed worker frees itself
 * after refresh.concurrency_slot_seconds (longer than the job timeout).
 */
class AccountConcurrency
{
    public function handle(object $job, Closure $next): void
    {
        $account = $job->getProfile()->account;

        Redis::funnel("refresh:concurrency:account:{$account->id}")
            ->limit(max(1, $account->max_concurrency))
            ->releaseAfter(config('refresh.concurrency_slot_seconds', 60))
            ->block(0)
            ->then(
                fn () => $next($job),
                function () use ($job) {
                    $delay = random_int(1, 3);
                    $job->logEvent('refresh.deferred', ['reason' => 'account_concurrency', 'delay_seconds' => $delay]);
                    $job->release($delay);
                },
            );
    }
}
