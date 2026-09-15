<?php

namespace App\Refresh;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Local crash test: kills the current worker with SIGKILL right after a successful database
 * write, before the queue job is acknowledged. Needs refresh.crash_injection=true and a
 * one-shot flag for the profile; does nothing otherwise.
 */
class CrashInjector
{
    public function flag(int $profileId): void
    {
        Redis::set($this->key($profileId), '1', 'EX', 600);
    }

    public function crashIfFlagged(int $profileId, string $jobUuid): void
    {
        if (! config('refresh.crash_injection') || ! Redis::getdel($this->key($profileId))) {
            return;
        }

        Log::channel('refresh')->warning('crash_injection.sigkill', [
            'profile_id' => $profileId,
            'job_uuid' => $jobUuid,
            'pid' => getmypid(),
        ]);

        posix_kill(getmypid(), SIGKILL);
    }

    private function key(int $profileId): string
    {
        return "refresh:crash_after_write:{$profileId}";
    }
}
