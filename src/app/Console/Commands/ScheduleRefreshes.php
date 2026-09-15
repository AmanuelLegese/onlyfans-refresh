<?php

namespace App\Console\Commands;

use App\Refresh\RefreshDispatcher;
use Illuminate\Console\Command;

class ScheduleRefreshes extends Command
{
    protected $signature = 'profiles:schedule-refreshes';

    protected $description = 'Dispatch refresh jobs for profiles that are due';

    public function handle(): int
    {
        $mode = config('refresh.mode', 'fixed');
        $dispatched = RefreshDispatcher::scheduleDueProfiles($mode);

        $this->info("Dispatched {$dispatched} refresh jobs (mode: {$mode})");

        return self::SUCCESS;
    }
}
