<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Profile;
use App\Models\RefreshAttempt;
use App\Refresh\RefreshDispatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * Two-account workload against the fake upstream, run once per mode for a before/after comparison.
 *
 * Account A (busy): 60 profiles plus 10 duplicate jobs. For the first RATE_LIMIT_SECONDS its
 * responses are 60% 429 (no Retry-After), 15% empty 500 and 10% slow (longer than the HTTP
 * timeout); after that every response is valid.
 * Account B (healthy): 10 profiles, always valid, mixed old/new formats, a new revision every 5s,
 * re-dispatched every B_ROUND_EVERY seconds during the first B_WINDOW seconds.
 */
class WorkloadRun extends Command
{
    protected $signature = 'workload:run
        {--mode=fixed : legacy or fixed}
        {--seed=42 : seed for the fake upstream responses}
        {--timeout=150 : max seconds to wait for the queue to drain}';

    protected $description = 'Run a two-account workload and write a JSON report';

    private const A_PROFILES = 60;

    private const DUPLICATES = 10;

    private const B_PROFILES = 10;

    private const RATE_LIMIT_SECONDS = 20;

    private const B_ROUND_EVERY = 2;

    private const B_WINDOW = 30;

    private const SLOW_MS = 12000;

    public function handle(): int
    {
        $mode = (string) $this->option('mode');
        $seed = (int) $this->option('seed');
        $maxWait = (int) $this->option('timeout');

        if (! in_array($mode, ['legacy', 'fixed'], true)) {
            $this->error('--mode must be legacy or fixed');

            return self::FAILURE;
        }

        $this->info("=== Workload run (mode={$mode}, seed={$seed}, timeout={$maxWait}s) ===");

        $this->resetData();
        [$accountA, $accountB] = $this->createAccounts();

        $startedAt = microtime(true);
        $this->setScenarios($accountA, $accountB, $seed, (int) $startedAt);

        $dispatched = ['A' => $this->dispatchAccountA($accountA, $mode), 'B' => $this->dispatchRound($accountB, $mode)];
        $this->info("Dispatched A={$dispatched['A']} (incl. ".self::DUPLICATES." duplicates), B={$dispatched['B']} to queue 'refresh'.");

        [$ageSamples, $drained] = $this->runUntilDrained($accountA, $accountB, $mode, $seed, $startedAt, $maxWait, $dispatched);

        $report = $this->buildReport($mode, $seed, $startedAt, $drained, $ageSamples, [
            'A' => [$accountA, $dispatched['A']],
            'B' => [$accountB, $dispatched['B']],
        ]);

        $path = storage_path("app/workload-{$mode}-{$seed}.json");
        file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT));

        $this->printSummary($report);
        $this->info("Report saved to: {$path}");

        return self::SUCCESS;
    }

    private function resetData(): void
    {
        DB::table('refresh_attempts')->truncate();
        DB::table('profiles')->truncate();
        DB::table('accounts')->truncate();

        Redis::del('queues:refresh', 'queues:refresh:delayed', 'queues:refresh:reserved', 'queues:refresh:notify');
        $this->deleteRedisKeys('fake:*');
        $this->deleteRedisKeys('refresh:*');
    }

    /**
     * Redis::keys() returns keys with the connection prefix, but Redis::del() adds the prefix again,
     * so strip it first or nothing gets deleted.
     */
    private function deleteRedisKeys(string $pattern): void
    {
        $prefix = (string) config('database.redis.options.prefix', '');

        foreach (Redis::keys($pattern) as $key) {
            Redis::del($prefix === '' ? $key : Str::after($key, $prefix));
        }
    }

    /**
     * @return array{0: Account, 1: Account}
     */
    private function createAccounts(): array
    {
        $accountA = Account::create([
            'name' => 'Account A (busy)',
            'credentials' => ['token' => 'token-a-'.Str::random(8)],
            'max_concurrency' => 2,
        ]);

        $accountB = Account::create([
            'name' => 'Account B (healthy)',
            'credentials' => ['token' => 'token-b-'.Str::random(8)],
            'max_concurrency' => 2,
        ]);

        // next_refresh_at is in the future so the scheduler container doesn't add its own jobs mid-run.
        $usernamesA = ['madison420ivy'];
        for ($i = 1; $i < self::A_PROFILES; $i++) {
            $usernamesA[] = 'user_a_'.str_pad((string) $i, 3, '0', STR_PAD_LEFT);
        }

        foreach ($usernamesA as $username) {
            Profile::create([
                'account_id' => $accountA->id,
                'username' => $username,
                'likes' => 120000,
                'revision' => 10,
                'next_refresh_at' => now()->addDay(),
            ]);
        }

        for ($i = 1; $i <= self::B_PROFILES; $i++) {
            Profile::create([
                'account_id' => $accountB->id,
                'username' => 'user_b_'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'likes' => 120005,
                'revision' => 5,
                'next_refresh_at' => now()->addDay(),
            ]);
        }

        return [$accountA, $accountB];
    }

    private function setScenarios(Account $accountA, Account $accountB, int $seed, int $startedAt): void
    {
        foreach ($accountA->profiles as $profile) {
            Redis::set("fake:scenario:{$profile->username}", json_encode($this->accountAScenario($seed, rateLimited: true)));
        }

        foreach ($accountB->profiles as $profile) {
            Redis::set("fake:scenario:{$profile->username}", json_encode([
                'format' => crc32($profile->username) % 2 === 0 ? 'new' : 'old',
                'p429' => 0,
                'p500_empty' => 0,
                'p_slow' => 0,
                'latency_ms' => 50,
                'seed' => "b-{$seed}",
                'revision_mode' => 'time',
                'base_revision' => 6,
                'started_at' => $startedAt,
            ]));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function accountAScenario(int $seed, bool $rateLimited): array
    {
        return [
            'format' => 'new',
            'p429' => $rateLimited ? 60 : 0,
            'p500_empty' => $rateLimited ? 15 : 0,
            'p_slow' => $rateLimited ? 10 : 0,
            'slow_ms' => self::SLOW_MS,
            'latency_ms' => 50,
            'seed' => "a-{$seed}",
            'revision_mode' => 'static',
            'revision' => 11,
            'likes' => 121000,
        ];
    }

    private function dispatchAccountA(Account $accountA, string $mode): int
    {
        $profiles = $accountA->profiles()->orderBy('id')->get(['id']);

        foreach ($profiles as $profile) {
            dispatch(RefreshDispatcher::jobFor($profile->id, $mode));
        }

        // Duplicate pushes that bypass the pending claim, to exercise the revision guard.
        foreach ($profiles->take(self::DUPLICATES) as $profile) {
            dispatch(RefreshDispatcher::jobFor($profile->id, $mode));
        }

        return $profiles->count() + min(self::DUPLICATES, $profiles->count());
    }

    private function dispatchRound(Account $account, string $mode): int
    {
        $profiles = $account->profiles()->orderBy('id')->get(['id']);

        foreach ($profiles as $profile) {
            dispatch(RefreshDispatcher::jobFor($profile->id, $mode));
        }

        return $profiles->count();
    }

    /**
     * @param  array{A: int, B: int}  $dispatched
     * @return array{0: list<array{all: float, A: float, B: float}>, 1: bool}
     */
    private function runUntilDrained(Account $accountA, Account $accountB, string $mode, int $seed, float $startedAt, int $maxWait, array &$dispatched): array
    {
        $profileAccount = $accountA->profiles()->pluck('id')->mapWithKeys(fn (int $id) => [$id => 'A'])
            ->union($accountB->profiles()->pluck('id')->mapWithKeys(fn (int $id) => [$id => 'B']))
            ->all();

        $ageSamples = [];
        $unblocked = false;
        $nextBRound = self::B_ROUND_EVERY;

        while (($elapsed = microtime(true) - $startedAt) < $maxWait) {
            if (! $unblocked && $elapsed >= self::RATE_LIMIT_SECONDS) {
                foreach ($accountA->profiles as $profile) {
                    Redis::set("fake:scenario:{$profile->username}", json_encode($this->accountAScenario($seed, rateLimited: false)));
                }
                $unblocked = true;
                $this->newLine();
                $this->info(sprintf('[%5.1fs] Account A upstream recovered: all responses valid from now on.', $elapsed));
            }

            if ($elapsed < self::B_WINDOW && $elapsed >= $nextBRound) {
                $dispatched['B'] += $this->dispatchRound($accountB, $mode);
                $nextBRound += self::B_ROUND_EVERY;
            }

            $sample = $this->oldestWaitingJobAges($profileAccount);
            $ageSamples[] = $sample;
            $pending = $this->pendingJobs();

            if ($unblocked && $elapsed >= self::B_WINDOW && $pending === 0) {
                $this->newLine();

                return [$ageSamples, true];
            }

            $this->output->write(sprintf("\r[%5.1fs] pending=%d oldest_waiting A=%.1fs B=%.1fs   ", $elapsed, $pending, $sample['A'], $sample['B']));
            usleep(500_000);
        }

        $this->newLine();

        return [$ageSamples, false];
    }

    /**
     * Oldest job in the ready list, overall and per account: seconds since it was first
     * dispatched (Horizon's pushedAt). A released job keeps its original pushedAt, so for
     * a rate-limited account this includes deliberate backoff.
     *
     * @param  array<int, string>  $profileAccount
     * @return array{all: float, A: float, B: float}
     */
    private function oldestWaitingJobAges(array $profileAccount): array
    {
        $now = microtime(true);
        $oldest = ['all' => 0.0, 'A' => 0.0, 'B' => 0.0];

        foreach (Redis::lrange('queues:refresh', 0, -1) as $payload) {
            $job = json_decode($payload, true);

            if (! isset($job['pushedAt'])) {
                continue;
            }

            $age = max(0.0, $now - (float) $job['pushedAt']);
            $oldest['all'] = max($oldest['all'], $age);

            if (preg_match('/"profileId";i:(\d+);/', $job['data']['command'] ?? '', $match) && isset($profileAccount[(int) $match[1]])) {
                $label = $profileAccount[(int) $match[1]];
                $oldest[$label] = max($oldest[$label], $age);
            }
        }

        return $oldest;
    }

    private function pendingJobs(): int
    {
        return (int) Redis::llen('queues:refresh')
            + (int) Redis::zcard('queues:refresh:delayed')
            + (int) Redis::zcard('queues:refresh:reserved');
    }

    /**
     * @param  list<array{all: float, A: float, B: float}>  $ageSamples
     * @param  array<string, array{0: Account, 1: int}>  $accounts
     * @return array<string, mixed>
     */
    private function buildReport(string $mode, int $seed, float $startedAt, bool $drained, array $ageSamples, array $accounts): array
    {
        $report = [
            'mode' => $mode,
            'seed' => $seed,
            'started_at' => date('c', (int) $startedAt),
            'elapsed_seconds' => round(microtime(true) - $startedAt, 1),
            'drained' => $drained,
            'workers' => (int) config('horizon.defaults.supervisor-refresh.maxProcesses'),
            'oldest_waiting_job_age_seconds' => $this->ageStats(array_column($ageSamples, 'all')),
            'duplicate_username_rows' => DB::table('profiles')->select('username')->groupBy('username')->havingRaw('COUNT(*) > 1')->get()->count(),
            'accounts' => [],
        ];

        foreach ($accounts as $label => [$account, $dispatched]) {
            $profiles = $account->profiles()->get();
            $attempts = RefreshAttempt::where('account_id', $account->id)->get();
            $successes = $attempts->where('outcome', 'success');
            $correct = $profiles->filter(fn (Profile $profile) => $this->matchesUpstream($label, $profile))->count();
            $successOffsets = $successes->map(fn (RefreshAttempt $a) => $a->created_at->getTimestamp() - (int) $startedAt)->sort()->values();

            $report['accounts'][$label] = [
                'name' => $account->name,
                'profiles' => $profiles->count(),
                'jobs_dispatched' => $dispatched,
                'upstream_attempts' => $attempts->count(),
                'attempts_marked_success' => $successes->count(),
                'attempts_per_success' => $successes->count() > 0 ? round($attempts->count() / $successes->count(), 2) : null,
                'profiles_with_correct_data' => $correct,
                'profiles_zeroed_or_revision_erased' => $profiles->filter(fn (Profile $p) => $p->likes === 0 || $p->revision === null)->count(),
                'oldest_waiting_job_age_seconds' => $this->ageStats(array_column($ageSamples, $label)),
                'first_success_after_seconds' => $successOffsets->first(),
                'longest_gap_between_successes_seconds' => $this->longestGap($successOffsets),
                'outcomes' => $attempts->countBy('outcome')->sortKeys()->all(),
                'successes_per_5s' => $successOffsets
                    ->countBy(fn (int $offset) => (string) (intdiv(max(0, $offset), 5) * 5))
                    ->sortKeys(SORT_NUMERIC)
                    ->all(),
                'madison420ivy' => $profiles->firstWhere('username', 'madison420ivy')?->only(['likes', 'revision', 'last_attempt_outcome']),
            ];
        }

        return $report;
    }

    /**
     * @param  list<float>  $samples
     * @return array{max: float, p95: float}
     */
    private function ageStats(array $samples): array
    {
        $sorted = collect($samples)->sort()->values();

        return [
            'max' => round((float) $sorted->max(), 1),
            'p95' => round($this->percentile($sorted, 95), 1),
        ];
    }

    /** Longest wait between consecutive successes, counting from the start of the run. */
    private function longestGap(Collection $sortedOffsets): ?int
    {
        if ($sortedOffsets->isEmpty()) {
            return null;
        }

        $gap = max(0, $sortedOffsets->first());
        $previous = $sortedOffsets->first();

        foreach ($sortedOffsets->slice(1) as $offset) {
            $gap = max($gap, $offset - $previous);
            $previous = $offset;
        }

        return $gap;
    }

    /** Stored data equals what the fake upstream serves for that profile. */
    private function matchesUpstream(string $accountLabel, Profile $profile): bool
    {
        if ($accountLabel === 'A') {
            return $profile->likes === 121000 && $profile->revision === 11;
        }

        return $profile->revision !== null && $profile->revision > 5 && $profile->likes === 120000 + $profile->revision;
    }

    private function percentile(Collection $sorted, int $percentile): float
    {
        if ($sorted->isEmpty()) {
            return 0.0;
        }

        return (float) $sorted->get(max(0, (int) ceil($sorted->count() * $percentile / 100) - 1));
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function printSummary(array $report): void
    {
        $this->newLine();
        $this->info("=== Summary: mode={$report['mode']}, elapsed={$report['elapsed_seconds']}s, drained=".($report['drained'] ? 'yes' : 'NO').' ===');

        $rows = [];
        foreach (['jobs_dispatched', 'upstream_attempts', 'attempts_marked_success', 'attempts_per_success', 'profiles_with_correct_data', 'profiles_zeroed_or_revision_erased', 'first_success_after_seconds', 'longest_gap_between_successes_seconds'] as $metric) {
            $rows[] = [$metric, $report['accounts']['A'][$metric] ?? 'n/a', $report['accounts']['B'][$metric] ?? 'n/a'];
        }
        foreach (['oldest_waiting_job_age_seconds', 'outcomes', 'successes_per_5s', 'madison420ivy'] as $metric) {
            $rows[] = [$metric, json_encode($report['accounts']['A'][$metric]), json_encode($report['accounts']['B'][$metric])];
        }

        $this->table(['Metric', 'A (busy)', 'B (healthy)'], $rows);
        $this->line("Oldest waiting job age, whole queue: max={$report['oldest_waiting_job_age_seconds']['max']}s, p95={$report['oldest_waiting_job_age_seconds']['p95']}s");
        $this->line("Duplicate username rows: {$report['duplicate_username_rows']}");
    }
}
