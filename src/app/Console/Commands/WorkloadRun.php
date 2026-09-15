<?php

namespace App\Console\Commands;

use App\Jobs\RefreshProfile;
use App\Models\{Account, Profile, RefreshAttempt};
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{DB, Redis};
use Illuminate\Support\Str;

class WorkloadRun extends Command
{
    protected $signature = 'workload:run
        {--mode=fixed : legacy or fixed}
        {--seed=42 : random seed}
        {--timeout=120 : max seconds to wait}';

    protected $description = 'Run a two-account workload and write a JSON report';

    private int $startedAt;
    private string $reportPath;

    public function handle(): int
    {
        $mode = $this->option('mode');
        $seed = (int) $this->option('seed');
        $maxWait = (int) $this->option('timeout');

        $this->startedAt = time();
        $this->reportPath = storage_path("app/workload-{$mode}-{$seed}.json");

        $this->info("=== Workload Run (mode={$mode}, seed={$seed}, timeout={$maxWait}s) ===");
        $this->newLine();

        $this->resetData();
        $accounts = $this->createAccounts();
        $this->setScenarios($accounts, $seed);
        $this->dispatchJobs($accounts, $mode);
        $this->scheduleUnblock($accounts, $seed, 20);
        $this->waitForDrain($maxWait);
        $report = $this->buildReport($accounts, $mode, $seed);
        $this->saveReport($report);
        $this->printSummary($report);

        return self::SUCCESS;
    }

    private function resetData(): void
    {
        DB::table('refresh_attempts')->truncate();
        DB::table('profiles')->truncate();
        DB::table('accounts')->truncate();

        $keys = Redis::keys('fake:*');
        if ($keys) {
            Redis::del($keys);
        }
        $keys = Redis::keys('refresh:*');
        if ($keys) {
            Redis::del($keys);
        }

        $this->info('Data reset complete.');
    }

    private function createAccounts(): array
    {
        $accountA = Account::create([
            'name' => 'Account A (busy)',
            'credentials' => ['token' => 'token-a-' . Str::random(8)],
            'max_concurrency' => 2,
        ]);

        $accountB = Account::create([
            'name' => 'Account B (healthy)',
            'credentials' => ['token' => 'token-b-' . Str::random(8)],
            'max_concurrency' => 2,
        ]);

        $usernamesA = ['madison420ivy'];
        for ($i = 1; $i < 60; $i++) {
            $usernamesA[] = 'user_a_' . str_pad($i, 3, '0', STR_PAD_LEFT);
        }

        foreach ($usernamesA as $username) {
            Profile::create([
                'account_id' => $accountA->id,
                'username' => $username,
                'likes' => 120000,
                'revision' => 10,
                'next_refresh_at' => now(),
            ]);
        }

        for ($i = 1; $i <= 10; $i++) {
            Profile::create([
                'account_id' => $accountB->id,
                'username' => 'user_b_' . str_pad($i, 3, '0', STR_PAD_LEFT),
                'likes' => 50000 + ($i * 1000),
                'revision' => 5,
                'next_refresh_at' => now(),
            ]);
        }

        $this->info("Created account A ({$accountA->id}) with 60 profiles");
        $this->info("Created account B ({$accountB->id}) with 10 profiles");
        $this->newLine();

        return ['a' => $accountA, 'b' => $accountB];
    }

    private function setScenarios(array $accounts, int $seed): void
    {
        $now = time();

        foreach ($accounts['a']->profiles as $profile) {
            $scenario = [
                'format' => 'new',
                'rate_limit_until' => $now + 20,
                'p429' => 60,
                'p500_empty' => 15,
                'p_slow' => 10,
                'slow_ms' => 3000,
                'latency_ms' => 100,
                'seed' => "a-{$seed}-{$profile->username}",
                'revision_mode' => 'static',
                'revision' => 11,
                'likes' => 121000,
            ];
            Redis::set("fake:scenario:{$profile->username}", json_encode($scenario));
        }

        foreach ($accounts['b']->profiles as $profile) {
            $format = ((int) crc32($profile->username) % 2 === 0) ? 'new' : 'old';
            $scenario = [
                'format' => $format,
                'rate_limit_until' => 0,
                'p429' => 0,
                'p500_empty' => 0,
                'p_slow' => 0,
                'slow_ms' => 100,
                'latency_ms' => 100,
                'seed' => "b-{$seed}-{$profile->username}",
                'revision_mode' => 'time',
                'base_revision' => 6,
                'started_at' => $now,
                'likes' => 51000,
            ];
            Redis::set("fake:scenario:{$profile->username}", json_encode($scenario));
        }

        $this->info('Scenarios set in Redis.');
        $this->newLine();
    }

    private function scheduleUnblock(array $accounts, int $seed, int $delaySeconds): void
    {
        // Store unblock data in Redis so waitForDrain can flip it after the delay
        Redis::set('workload:unblock:account_id', $accounts['a']->id);
        Redis::set('workload:unblock:seed', $seed);
        Redis::set('workload:unblock:at', time() + $delaySeconds);
        $this->info("Account A will unblock in {$delaySeconds}s.");
    }

    private function dispatchJobs(array $accounts, string $mode): void
    {
        $profilesA = $accounts['a']->profiles()->get();
        foreach ($profilesA as $profile) {
            RefreshProfile::dispatch($profile->id, $mode)->onQueue('refresh');
        }

        $dupProfiles = $profilesA->random(10);
        foreach ($dupProfiles as $profile) {
            RefreshProfile::dispatch($profile->id, $mode)->onQueue('refresh');
        }

        $profilesB = $accounts['b']->profiles()->get();
        foreach ($profilesB as $profile) {
            RefreshProfile::dispatch($profile->id, $mode)->onQueue('refresh');
        }

        $total = $profilesA->count() + 10 + $profilesB->count();
        $this->info("Dispatched {$total} jobs to queue 'refresh'.");
        $this->newLine();
    }

    private function waitForDrain(int $maxWait): void
    {
        $this->info("Waiting for queue to drain (max {$maxWait}s)...");
        $start = time();
        $unblocked = false;

        while ((time() - $start) < $maxWait) {
            $pending = Redis::llen('queues:refresh');

            if (!$unblocked && time() >= (int) Redis::get('workload:unblock:at')) {
                $this->unblockAccountA();
                $unblocked = true;
            }

            if ($pending === 0) {
                $this->info('Queue drained.');
                break;
            }
            $this->output->write("\r  Pending: {$pending}  ");
            usleep(500_000);
        }

        $elapsed = time() - $start;
        $this->info("Elapsed: {$elapsed}s");
        $this->newLine();
    }

    private function unblockAccountA(): void
    {
        $accountId = Redis::get('workload:unblock:account_id');
        $seed = Redis::get('workload:unblock:seed');

        if (!$accountId || !$seed) {
            return;
        }

        $account = Account::find($accountId);
        if (!$account) {
            return;
        }

        foreach ($account->profiles as $profile) {
            $scenario = [
                'format' => 'new',
                'rate_limit_until' => 0,
                'p429' => 0,
                'p500_empty' => 0,
                'p_slow' => 0,
                'slow_ms' => 100,
                'latency_ms' => 100,
                'seed' => "a-{$seed}-{$profile->username}",
                'revision_mode' => 'static',
                'revision' => 11,
                'likes' => 121000,
            ];
            Redis::set("fake:scenario:{$profile->username}", json_encode($scenario));
        }

        $this->info('Account A unblocked: all profiles set to all-ok.');
    }

    private function buildReport(array $accounts, string $mode, int $seed): array
    {
        $report = [
            'mode' => $mode,
            'seed' => $seed,
            'started_at' => date('c', $this->startedAt),
            'ended_at' => date('c'),
            'elapsed_seconds' => time() - $this->startedAt,
            'accounts' => [],
        ];

        foreach ($accounts as $key => $account) {
            $accountLabel = strtoupper($key);
            $profiles = $account->profiles()->get();
            $attempts = RefreshAttempt::where('account_id', $account->id)->get();

            $successes = $attempts->where('outcome', 'success');
            $stales = $attempts->where('outcome', 'stale_revision');
            $rateLimited = $attempts->where('outcome', 'rate_limited');
            $serverErrors = $attempts->where('outcome', 'server_error');
            $timeouts = $attempts->where('outcome', 'timeout');
            $malformed = $attempts->where('outcome', 'malformed');
            $clientErrors = $attempts->where('outcome', 'client_error');

            $verifiedSuccesses = 0;
            foreach ($profiles as $profile) {
                if ($profile->last_attempt_outcome === 'success' && $profile->revision >= 11 && $profile->likes > 120000) {
                    $verifiedSuccesses++;
                }
            }

            $durations = $attempts->pluck('duration_ms')->filter()->values()->sort()->values();
            $p50 = $durations->median();
            $p95Index = (int) ceil($durations->count() * 0.95) - 1;
            $p95 = $durations->get(max(0, $p95Index));

            $wrongData = $profiles->filter(function ($p) {
                return $p->likes === 0 || $p->revision < 10;
            })->count();

            $duplicateUsernames = DB::table('profiles')
                ->select('username')
                ->where('account_id', $account->id)
                ->groupBy('username')
                ->havingRaw('COUNT(*) > 1')
                ->count();

            $report['accounts'][$accountLabel] = [
                'account_id' => $account->id,
                'name' => $account->name,
                'profiles_count' => $profiles->count(),
                'dispatched' => $attempts->count(),
                'successes' => $successes->count(),
                'verified_successes' => $verifiedSuccesses,
                'stale_rejections' => $stales->count(),
                'rate_limited' => $rateLimited->count(),
                'server_errors' => $serverErrors->count(),
                'timeouts' => $timeouts->count(),
                'malformed' => $malformed->count(),
                'client_errors' => $clientErrors->count(),
                'attempts_per_verified_success' => $verifiedSuccesses > 0
                    ? round($attempts->count() / $verifiedSuccesses, 2)
                    : null,
                'median_duration_ms' => $p50,
                'p95_duration_ms' => $p95,
                'wrong_data_profiles' => $wrongData,
                'duplicate_usernames' => $duplicateUsernames,
                'profiles' => $profiles->map(function ($p) {
                    return [
                        'username' => $p->username,
                        'likes' => $p->likes,
                        'revision' => $p->revision,
                        'last_attempt_outcome' => $p->last_attempt_outcome,
                        'next_refresh_at' => $p->next_refresh_at?->toISOString(),
                    ];
                })->toArray(),
            ];
        }

        return $report;
    }

    private function saveReport(array $report): void
    {
        file_put_contents($this->reportPath, json_encode($report, JSON_PRETTY_PRINT));
        $this->info("Report saved to: {$this->reportPath}");
    }

    private function printSummary(array $report): void
    {
        $this->newLine();
        $this->info('=== Summary ===');
        $this->info("Mode: {$report['mode']}");
        $this->info("Elapsed: {$report['elapsed_seconds']}s");

        foreach ($report['accounts'] as $label => $data) {
            $this->newLine();
            $this->info("--- {$label}: {$data['name']} ---");
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Profiles', $data['profiles_count']],
                    ['Dispatched', $data['dispatched']],
                    ['Successes', $data['successes']],
                    ['Verified Successes', $data['verified_successes']],
                    ['Stale Rejections', $data['stale_rejections']],
                    ['Rate Limited', $data['rate_limited']],
                    ['Server Errors', $data['server_errors']],
                    ['Timeouts', $data['timeouts']],
                    ['Malformed', $data['malformed']],
                    ['Client Errors', $data['client_errors']],
                    ['Attempts/Verified Success', $data['attempts_per_verified_success'] ?? 'N/A'],
                    ['Median Duration (ms)', $data['median_duration_ms'] ?? 'N/A'],
                    ['P95 Duration (ms)', $data['p95_duration_ms'] ?? 'N/A'],
                    ['Wrong Data Profiles', $data['wrong_data_profiles']],
                    ['Duplicate Usernames', $data['duplicate_usernames']],
                ]
            );
        }
    }
}
