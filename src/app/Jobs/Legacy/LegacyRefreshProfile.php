<?php

namespace App\Jobs\Legacy;

use App\Models\Profile;
use App\Models\RefreshAttempt;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * The original, broken handler. Kept only to reproduce the incident and to run the
 * "before" workload; never use it as a rollback target.
 */
class LegacyRefreshProfile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout;

    public function __construct(
        public int $profileId,
    ) {
        $this->onQueue('refresh');
        $this->timeout = config('refresh.job_timeout', 30);
    }

    public function handle(): void
    {
        $profile = Profile::findOrFail($this->profileId);
        $start = microtime(true);

        $baseUrl = config('refresh.upstream_url', 'http://upstream:8081');
        $response = Http::get("{$baseUrl}/fake/api/users/{$profile->username}");

        // BUG: no status check, so 429 and 500 responses are parsed like a valid profile.
        $json = $response->json();
        $json = is_array($json) ? $json : [];

        // BUG: reads top-level 'likes' only and defaults a missing value to 0.
        $likes = $json['likes'] ?? 0;

        // BUG: every response is written as a successful refresh, with no validation
        // and no revision check.
        $profile->update([
            'likes' => $likes,
            'revision' => $json['revision'] ?? null,
            'name' => $json['name'] ?? null,
            'avatar_url' => $json['avatar_url'] ?? null,
            'posts_count' => $json['posts_count'] ?? null,
            'photos_count' => $json['photos_count'] ?? null,
            'videos_count' => $json['videos_count'] ?? null,
            'last_attempt_at' => now(),
            'last_attempt_outcome' => 'success',
            'last_success_at' => now(),
        ]);

        // Instrumentation only, not part of the original handler: one attempt row per run,
        // so legacy and fixed workloads are measured from the same table.
        RefreshAttempt::create([
            'profile_id' => $profile->id,
            'account_id' => $profile->account_id,
            'job_uuid' => $this->job?->uuid() ?? Str::uuid()->toString(),
            'queue_attempt' => $this->attempts(),
            'upstream_attempt' => $this->attempts(),
            'mode' => 'legacy',
            'outcome' => 'success',
            'http_status' => $response->status(),
            'revision' => $json['revision'] ?? null,
            'duration_ms' => (int) ((microtime(true) - $start) * 1000),
            'created_at' => now(),
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        $profile = Profile::find($this->profileId);
        if ($profile) {
            $profile->update([
                'last_attempt_outcome' => 'failed',
                'last_failure_reason' => 'exception',
                'last_failure_detail' => substr($exception->getMessage(), 0, 500),
            ]);
        }
    }
}
