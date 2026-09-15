<?php

namespace App\Refresh;

use App\Models\Account;
use App\Models\Profile;
use App\Models\RefreshAttempt;
use Illuminate\Support\Facades\DB;

class ProfileWriter
{
    /**
     * Race-safe: relies on the unique username index. If another worker inserted the row first,
     * the unique violation is caught (inside a savepoint when a transaction is open) and the
     * existing row is returned.
     */
    public static function createOrFirst(Account $account, string $username): Profile
    {
        return Profile::createOrFirst(['username' => $username], ['account_id' => $account->id]);
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
            ->update(array_filter([
                'upstream_id' => $payload->upstreamId,
            ], fn ($value) => $value !== null) + [
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
                'refresh_queued_at' => null,
            ]);

        if ($updated === 0) {
            $outcome = 'stale_revision';
            DB::table('profiles')
                ->where('id', $profile->id)
                ->update([
                    'last_attempt_at' => now(),
                    'last_attempt_outcome' => $outcome,
                    'refresh_queued_at' => null,
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

        // recordFailure() already counted this failure; only schedule the backoff here.
        $profile->update([
            'refresh_queued_at' => null,
            'next_refresh_at' => now()->addMinutes($delayMinutes),
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
            // When the scheduler or a manual refresh claimed the profile; null for direct dispatches.
            'queued_at' => $profile->refresh_queued_at,
            // $timestamps is off on RefreshAttempt, so set this explicitly; reports bucket by it.
            'created_at' => now(),
        ]);
    }
}
