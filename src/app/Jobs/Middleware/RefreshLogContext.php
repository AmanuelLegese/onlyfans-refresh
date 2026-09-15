<?php

namespace App\Jobs\Middleware;

use Closure;

/** Logs the start of every attempt; later events reuse the same context via the job. */
class RefreshLogContext
{
    public function handle(object $job, Closure $next): void
    {
        $job->logEvent('refresh.started');

        $next($job);
    }
}
