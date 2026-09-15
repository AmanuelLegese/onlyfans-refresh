<?php

namespace App\Jobs\Legacy;

use App\Models\{Account, Profile};
use App\Refresh\Exceptions\{ClientError, MalformedResponse, RateLimited, ServerError, UpstreamTimeout};
use App\Upstream\FakeUpstreamClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
use Illuminate\Support\Facades\Http;

class LegacyRefreshProfile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout;

    public function __construct(
        public int $profileId,
    ) {
        $this->timeout = config('refresh.job_timeout', 30);
    }

    public function handle(): void
    {
        $profile = Profile::findOrFail($this->profileId);
        $account = $profile->account;

        $baseUrl = config('refresh.upstream_url', 'http://upstream:8081');
        $url = "{$baseUrl}/fake/api/users/{$profile->username}";

        $response = Http::get($url);

        $json = $response->json();

        if (!is_array($json)) {
            $this->recordFailure($profile, $account, 'malformed', null, 'Non-array response');
            return;
        }

        // BUG: reads top-level 'likes', defaults to 0, never checks profile.likes
        $likes = $json['likes'] ?? 0;

        // BUG: always marks as success regardless of response
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

        // BUG: no refresh_attempts row is created
        // BUG: no validation of likes or revision
        // BUG: no handling of 429, 500, timeouts, etc.
    }

    private function recordFailure(Profile $profile, Account $account, string $reason, ?int $httpStatus, string $detail): void
    {
        $profile->update([
            'last_attempt_at' => now(),
            'last_attempt_outcome' => $reason,
            'last_failure_at' => now(),
            'last_failure_reason' => $reason,
            'last_failure_detail' => $detail,
            'consecutive_failures' => $profile->consecutive_failures + 1,
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
