<?php

namespace App\Jobs\Middleware;

use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RefreshLogContext
{
    public function handle(object $job, Closure $next): void
    {
        $profile = $job->getProfile();
        $account = $profile->account;

        Log::channel('refresh')->info('refresh.started', [
            'mode' => $job->getMode(),
            'account_id' => $account->id,
            'profile_id' => $profile->id,
            'username' => $profile->username,
            'job_uuid' => $job->job?->uuid() ?? Str::uuid(),
            'queue_attempt' => $job->getQueueAttempt(),
            'upstream_attempt' => $job->getUpstreamAttempt(),
        ]);

        $next($job);
    }
}
