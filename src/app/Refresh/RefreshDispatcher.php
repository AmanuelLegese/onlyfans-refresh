<?php

namespace App\Refresh;

use App\Jobs\RefreshProfile;
use App\Models\Profile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class RefreshDispatcher
{
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

        RefreshProfile::dispatch($profile->id, $mode);

        return true;
    }

    public static function scheduleDueProfiles(string $mode = 'fixed'): int
    {
        $dispatched = 0;

        DB::table('profiles')
            ->select('id')
            ->where('next_refresh_at', '<=', now())
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
