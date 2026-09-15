<?php

namespace App\Console\Commands;

use App\Models\{Account, Profile, RefreshAttempt};
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{DB, Redis};

class WorkloadCrashReplay extends Command
{
    protected $signature = 'workload:crash-replay
        {--username=crash_test_user : Profile username}
        {--timeout=120 : Max seconds to wait for second attempt}';

    protected $description = 'Simulate a worker crash mid-apply and verify idempotent replay';

    public function handle(): int
    {
        $username = $this->option('username');
        $maxWait = (int) $this->option('timeout');

        $this->info("=== Crash Replay Test (username={$username}) ===");
        $this->newLine();

        // 1. Create account and profile pinned to static revision
        $account = Account::firstOrCreate(
            ['name' => 'Crash Test Account'],
            ['credentials' => ['token' => 'crash-token'], 'max_concurrency' => 1]
        );

        $profile = Profile::firstOrCreate(
            ['username' => $username],
            [
                'account_id' => $account->id,
                'likes' => 120000,
                'revision' => 10,
                'next_refresh_at' => now(),
            ]
        );

        $this->info("Profile {$username}: likes={$profile->likes}, revision={$profile->revision}");
        $this->newLine();

        // 2. Set the crash flag in Redis (consumed by the job)
        Redis::set("crash:flag:{$profile->id}", '1');

        // 3. Set a static upstream scenario
        $scenario = [
            'format' => 'new',
            'rate_limit_until' => 0,
            'p429' => 0,
            'p500_empty' => 0,
            'p_slow' => 0,
            'slow_ms' => 100,
            'latency_ms' => 100,
            'seed' => "crash-{$username}",
            'revision_mode' => 'static',
            'revision' => 11,
            'likes' => 121000,
        ];
        Redis::set("fake:scenario:{$username}", json_encode($scenario));

        // 4. Record attempt count before dispatch
        $attemptsBefore = RefreshAttempt::where('profile_id', $profile->id)->count();

        $this->info("Dispatching job with crash flag set...");
        $this->info("The job should:");
        $this->info("  1. Fetch upstream → success");
        $this->info("  2. Apply success (revision 10 → 11)");
        $this->info("  3. Crash via SIGKILL (simulated by exception)");
        $this->info("  4. Retry after delay → stale_revision");
        $this->newLine();

        // 5. Dispatch using a special crash-mode job class
        // We'll dispatch a regular RefreshProfile but set a flag that the
        // FakeUpstreamController will read and the job will check
        \App\Jobs\RefreshProfile::dispatch($profile->id, 'fixed')
            ->onQueue('refresh');

        // 6. Monitor for the second attempt
        $this->info("Waiting for retry_after (90s) + processing...");
        $this->info("Monitoring refresh_attempts table...");
        $this->newLine();

        $startTime = time();
        $secondAttemptFound = false;

        while ((time() - $startTime) < $maxWait) {
            $attempts = RefreshAttempt::where('profile_id', $profile->id)
                ->orderBy('created_at')
                ->get();

            if ($attempts->count() >= 2) {
                $secondAttemptFound = true;
                break;
            }

            // Show progress
            $elapsed = time() - $startTime;
            $this->output->write("\r  Elapsed: {$elapsed}s, attempts: {$attempts->count()}  ");
            sleep(2);
        }

        $this->newLine();
        $this->newLine();

        // 7. Refresh profile and show results
        $profile->refresh();
        $attempts = RefreshAttempt::where('profile_id', $profile->id)
            ->orderBy('created_at')
            ->get();

        $this->info("--- Profile After ---");
        $this->table(
            ['Field', 'Value'],
            [
                ['likes', $profile->likes],
                ['revision', $profile->revision],
                ['last_attempt_outcome', $profile->last_attempt_outcome ?? 'null'],
            ]
        );

        $this->info("--- Attempts Timeline ---");
        foreach ($attempts as $i => $attempt) {
            $this->info("  Attempt " . ($i + 1) . ":");
            $this->table(
                ['Field', 'Value'],
                [
                    ['outcome', $attempt->outcome],
                    ['http_status', $attempt->http_status ?? 'null'],
                    ['revision', $attempt->revision ?? 'null'],
                    ['duration_ms', $attempt->duration_ms ?? 'null'],
                    ['job_uuid', $attempt->job_uuid ?? 'null'],
                    ['queue_attempt', $attempt->queue_attempt ?? 'null'],
                    ['upstream_attempt', $attempt->upstream_attempt ?? 'null'],
                    ['created_at', $attempt->created_at->toISOString()],
                ]
            );
            $this->newLine();
        }

        // 8. Verify expected outcome
        if ($attempts->count() >= 2) {
            $first = $attempts[0];
            $second = $attempts[1];

            $this->info("=== Verification ===");

            if ($first->outcome === 'success' && $second->outcome === 'stale_revision') {
                $this->info("PASS: First attempt succeeded, second was stale_revision");
            } else {
                $this->warn("UNEXPECTED: First={$first->outcome}, Second={$second->outcome}");
            }

            if ($profile->revision === 11) {
                $this->info("PASS: Profile revision is 11 (correct)");
            } else {
                $this->warn("FAIL: Profile revision is {$profile->revision}, expected 11");
            }

            if ($profile->likes === 121000) {
                $this->info("PASS: Profile likes is 121000 (correct)");
            } else {
                $this->warn("FAIL: Profile likes is {$profile->likes}, expected 121000");
            }
        } else {
            $this->warn("Only {$attempts->count()} attempt(s) recorded. Worker may not have retried.");
            $this->warn("Check Horizon dashboard: http://localhost/horizon");
        }

        return self::SUCCESS;
    }
}
