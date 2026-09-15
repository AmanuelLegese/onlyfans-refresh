<?php

namespace App\Console\Commands;

use App\Jobs\RefreshProfile;
use App\Models\Account;
use App\Models\RefreshAttempt;
use App\Refresh\ProfileWriter;
use App\Refresh\RefreshDispatcher;
use App\Upstream\ProfileSource;
use Illuminate\Console\Command;

/**
 * Refreshes a public OnlyFans profile from the real API (logged out, signed request) and prints
 * what was stored. By default the refresh runs through Horizon like any other profile.
 */
class RefreshOnlyFansProfile extends Command
{
    protected $signature = 'onlyfans:refresh
        {username=madison420ivy : OnlyFans username}
        {--sync : Run the refresh in this process instead of through Horizon}
        {--timeout=60 : Seconds to wait for the queued job}';

    protected $description = 'Refresh a public OnlyFans profile from the real API and show the stored data';

    public function handle(): int
    {
        $username = (string) $this->argument('username');

        $account = Account::firstOrCreate(
            ['name' => 'OnlyFans (public, logged out)'],
            ['source' => Account::SOURCE_ONLYFANS, 'credentials' => [], 'max_concurrency' => 1],
        );

        $profile = ProfileWriter::createOrFirst($account, $username);

        if ($profile->account_id !== $account->id) {
            $profile->update(['account_id' => $account->id]);
            $this->line("Moved @{$username} to the live OnlyFans account.");
        }

        $lastAttemptId = (int) RefreshAttempt::where('profile_id', $profile->id)->max('id');

        if ($this->option('sync')) {
            (new RefreshProfile($profile->id))->handle(app(ProfileSource::class));
        } elseif (! RefreshDispatcher::dispatchIfNotPending($profile, 'fixed')) {
            $this->warn("A refresh is already pending for @{$username}; waiting for it.");
        } else {
            $this->line("Dispatched a refresh for @{$username} to the 'refresh' queue.");
        }

        $attempt = $this->waitForAttempt($profile->id, $lastAttemptId, $this->option('sync') ? 0 : (int) $this->option('timeout'));

        if (! $attempt) {
            $this->error('No attempt recorded in time. Is Horizon running?');

            return self::FAILURE;
        }

        $profile->refresh();
        $data = $profile->profile_data ?? [];

        $this->table(['Attempt', 'Value'], [
            ['outcome', $attempt->outcome],
            ['http_status', $attempt->http_status ?? '-'],
            ['duration_ms', $attempt->duration_ms],
            ['job_uuid', $attempt->job_uuid],
            ['queue_attempt', $attempt->queue_attempt],
        ]);

        $this->table(['Stored field', 'Value'], [
            ['upstream_id', $profile->upstream_id ?? '-'],
            ['username', $profile->username],
            ['name', $profile->name ?? '-'],
            ['likes (favoritedCount)', $profile->likes === null ? '-' : number_format($profile->likes)],
            ['posts / photos / videos', "{$profile->posts_count} / {$profile->photos_count} / {$profile->videos_count}"],
            ['revision (request start, ms)', $profile->revision ?? '-'],
            ['last_success_at', $profile->last_success_at?->toIso8601String() ?? '-'],
            ['next_refresh_at', $profile->next_refresh_at?->toIso8601String() ?? '-'],
            ['last_failure', $profile->last_failure_reason ? "{$profile->last_failure_reason}: {$profile->last_failure_detail}" : '-'],
            ['joinDate / isVerified', ($data['joinDate'] ?? '-').' / '.json_encode($data['isVerified'] ?? null)],
            ['profile_data keys', implode(', ', array_keys($data))],
        ]);

        return $attempt->outcome === 'success' ? self::SUCCESS : self::FAILURE;
    }

    private function waitForAttempt(int $profileId, int $afterId, int $timeout): ?RefreshAttempt
    {
        $deadline = microtime(true) + $timeout;

        do {
            $attempt = RefreshAttempt::where('profile_id', $profileId)->where('id', '>', $afterId)->orderByDesc('id')->first();

            if ($attempt || microtime(true) >= $deadline) {
                return $attempt;
            }

            sleep(1);
        } while (true);
    }
}
