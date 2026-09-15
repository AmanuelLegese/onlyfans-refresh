<?php

namespace App\Refresh;

use App\Models\Account;
use App\Models\Profile;
use App\Models\RefreshAttempt;
use Illuminate\Support\Facades\DB;

class ProfileWriter
{
    public static function createOrFirst(Account $account, string $username): Profile
    {
        return DB::table('profiles')
            ->where('username', $username)
            ->lockForUpdate()
            ->first()
            ? Profile::where('username', $username)->first()
            : Profile::create([
                'account_id' => $account->id,
                'username' => $username,
            ]);
    }

    public static function applySuccess(
        Profile $profile,
        Account $account,
        ProfilePayload $payload,
        string $jobUuid,
        int $httpStatus,
        int $durationMs,
        int $queueAttempt = 1,
        int $upstreamAttempt = 1,
        string $mode = 'fixed',
    ): string {
        $outcome = 'success';

        $updated = DB::table('profiles')
            ->where('id', $profile->id)
            ->where(function ($query) use ($payload) {
                $query->whereNull('revision')
                    ->orWhere('revision', '<', $payload->revision);
            })
            ->update([
                'likes' => $payload->likes,
                'revision' => $payload->revision,
                'name' => $payload->name,
                'avatar_url' => $payload->avatarUrl,
                'posts_count' => $payload->postsCount,
                'photos_count' => $payload->photosCount,
                'videos_count' => $payload->videosCount,
                'profile_data' => $payload->profileData ? json_encode($payload->profileData) : null,
                'last_attempt_at' => now(),
                'last_attempt_outcome' => $outcome,
                'last_success_at' => now(),
                'consecutive_failures' => 0,
                'next_refresh_at' => RefreshPolicy::nextRefreshAt($payload->likes),
            ]);

        if ($updated === 0) {
            $outcome = 'stale_revision';
            DB::table('profiles')
                ->where('id', $profile->id)
                ->update([
                    'last_attempt_at' => now(),
                    'last_attempt_outcome' => $outcome,
                ]);
        }

        self::logAttempt($profile, $account, $jobUuid, $queueAttempt, $upstreamAttempt, $mode, $outcome, $httpStatus, $payload->revision, $durationMs);

        return $outcome;
    }

    public static function recordFailure(
        Profile $profile,
        Account $account,
        string $jobUuid,
        string $outcome,
        ?int $httpStatus,
        ?string $detail,
        int $durationMs,
        int $queueAttempt = 1,
        int $upstreamAttempt = 1,
        string $mode = 'fixed',
    ): void {
        $profile->update([
            'last_attempt_at' => now(),
            'last_attempt_outcome' => $outcome,
            'last_failure_at' => now(),
            'last_failure_reason' => $outcome,
            'last_failure_detail' => substr($detail ?? '', 0, 500),
            'consecutive_failures' => $profile->consecutive_failures + 1,
        ]);

        self::logAttempt($profile, $account, $jobUuid, $queueAttempt, $upstreamAttempt, $mode, $outcome, $httpStatus, $profile->revision, $durationMs);
    }

    public static function giveUp(Profile $profile): void
    {
        $failures = $profile->consecutive_failures;
        $baseMinutes = config('refresh.giveup_base_minutes', 5);
        $capHours = config('refresh.giveup_cap_hours', 6);
        $delayMinutes = min($baseMinutes * (2 ** $failures), $capHours * 60);

        $profile->update([
            'refresh_queued_at' => null,
            'next_refresh_at' => now()->addMinutes($delayMinutes),
            'consecutive_failures' => $failures + 1,
        ]);
    }

    private static function logAttempt(
        Profile $profile,
        Account $account,
        string $jobUuid,
        int $queueAttempt,
        int $upstreamAttempt,
        string $mode,
        string $outcome,
        ?int $httpStatus,
        ?int $revision,
        ?int $durationMs,
    ): void {
        RefreshAttempt::create([
            'profile_id' => $profile->id,
            'account_id' => $account->id,
            'job_uuid' => $jobUuid,
            'queue_attempt' => $queueAttempt,
            'upstream_attempt' => $upstreamAttempt,
            'mode' => $mode,
            'outcome' => $outcome,
            'http_status' => $httpStatus,
            'revision' => $revision,
            'duration_ms' => $durationMs,
            'queued_at' => now(),
            // $timestamps is off on RefreshAttempt, so set this explicitly; reports bucket by it.
            'created_at' => now(),
        ]);
    }
}
