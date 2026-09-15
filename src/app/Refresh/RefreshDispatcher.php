<?php

namespace App\Refresh;

use App\Jobs\Legacy\LegacyRefreshProfile;
use App\Jobs\RefreshProfile;
use App\Models\Profile;
use Illuminate\Support\Facades\DB;

class RefreshDispatcher
{
    /**
     * The job class for a refresh mode: `legacy` is the original broken handler (kept only to
     * reproduce the incident and compare workloads), `fixed` is the current handler.
     */
    public static function jobFor(int $profileId, string $mode): RefreshProfile|LegacyRefreshProfile
    {
        return match ($mode) {
            'legacy' => new LegacyRefreshProfile($profileId),
            'fixed' => new RefreshProfile($profileId, 'fixed'),
            default => throw new \InvalidArgumentException("Unknown refresh mode [{$mode}]; use legacy or fixed."),
        };
    }

    public static function dispatchIfNotPending(Profile $profile, string $mode = 'fixed'): bool
    {
        $claimed = DB::table('profiles')
            ->where('id', $profile->id)
            ->where(function ($query) {
                $query->whereNull('refresh_queued_at')
                    ->orWhere('refresh_queued_at', '<', now()->subHour());
            })
            ->update(['refresh_queued_at' => now()]);

        if ($claimed === 0) {
            return false;
        }

        dispatch(self::jobFor($profile->id, $mode));

        return true;
    }

    public static function scheduleDueProfiles(string $mode = 'fixed'): int
    {
        $dispatched = 0;

        DB::table('profiles')
            ->select('id')
            // Never-refreshed profiles (null) are due immediately.
            ->where(function ($query) {
                $query->whereNull('next_refresh_at')
                    ->orWhere('next_refresh_at', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('refresh_queued_at')
                    ->orWhere('refresh_queued_at', '<', now()->subHour());
            })
            ->lazyById(500)
            ->each(function ($row) use ($mode, &$dispatched) {
                $profile = Profile::find($row->id);
                if ($profile && self::dispatchIfNotPending($profile, $mode)) {
                    $dispatched++;
                }
            });

        return $dispatched;
    }
}
