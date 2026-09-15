<?php

namespace App\Console\Commands;

use App\Models\Profile;
use App\Refresh\RefreshDispatcher;
use Illuminate\Console\Command;

class RefreshProfileCommand extends Command
{
    protected $signature = 'profile:refresh {username : The profile username to refresh} {--mode=fixed : legacy or fixed}';

    protected $description = 'Manually dispatch a refresh for a specific profile';

    public function handle(): int
    {
        $username = $this->argument('username');
        $mode = $this->option('mode');

        $profile = Profile::where('username', $username)->first();

        if (!$profile) {
            $this->error("Profile not found: {$username}");
            return self::FAILURE;
        }

        $dispatched = RefreshDispatcher::dispatchIfNotPending($profile, $mode);

        if ($dispatched) {
            $this->info("Dispatched refresh for {$username} (mode: {$mode})");
        } else {
            $this->warn("Refresh already pending for {$username}");
        }

        return self::SUCCESS;
    }
}
