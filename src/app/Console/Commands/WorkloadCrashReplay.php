<?php

namespace App\Console\Commands;

use App\Jobs\RefreshProfile;
use App\Models\Account;
use App\Models\RefreshAttempt;
use App\Refresh\CrashInjector;
use App\Refresh\ProfileWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * Kills a Horizon worker with SIGKILL right after its database write (before the job is
 * acknowledged), waits for Redis to hand the same job to another worker, and checks the replay
 * changed nothing. Needs REFRESH_CRASH_INJECTION=true in the horizon container.
 */
class WorkloadCrashReplay extends Command
{
    protected $signature = 'workload:crash-replay
        {--username=crash_test_user : Profile username}
        {--timeout=240 : Max seconds to wait for the replayed attempt}';

    protected $description = 'Kill a worker after its database write and verify the replayed job is idempotent';

    public function handle(CrashInjector $crash): int
    {
        $username = (string) $this->option('username');
        $maxWait = (int) $this->option('timeout');

        $account = Account::firstOrCreate(
            ['name' => 'Crash Replay'],
            ['credentials' => ['token' => 'crash-token'], 'max_concurrency' => 1],
        );

        $profile = ProfileWriter::createOrFirst($account, $username);
        $profile->forceFill([
            'account_id' => $account->id,
            'likes' => 120000,
            'revision' => 10,
            'last_attempt_outcome' => null,
            'refresh_queued_at' => null,
            'next_refresh_at' => now()->addDay(),
        ])->save();
        RefreshAttempt::where('profile_id', $profile->id)->delete();

        Redis::set("fake:scenario:{$username}", json_encode([
            'format' => 'new',
            'revision_mode' => 'static',
            'revision' => 11,
            'likes' => 121000,
            'latency_ms' => 50,
            'seed' => 'crash-replay',
        ]));
        $crash->flag($profile->id);

        $startedAt = microtime(true);
        dispatch(new RefreshProfile($profile->id));

        $this->line("Dispatched RefreshProfile for {$username} (stored: 120000 / rev 10; upstream serves 121000 / rev 11).");
        $this->line('Expected: attempt 1 writes rev 11 and the worker is killed before the ack. After retry_after (90s)');
        $this->line('and the profile lock expiry (120s), Redis hands the same job out again and it records stale_revision.');

        $attempts = collect();
        while (($elapsed = microtime(true) - $startedAt) < $maxWait) {
            $attempts = RefreshAttempt::where('profile_id', $profile->id)->orderBy('id')->get();

            if ($attempts->count() >= 2) {
                break;
            }

            $this->output->write(sprintf("\r[%5.1fs] attempts recorded: %d   ", $elapsed, $attempts->count()));
            sleep(2);
        }
        $this->newLine();

        $this->printTimeline($attempts, $startedAt);

        $profile->refresh();
        $first = $attempts->get(0);
        $second = $attempts->get(1);

        $checks = [
            'attempt 1 wrote the new revision (success)' => $first?->outcome === 'success',
            'the crash flag was consumed (worker was killed)' => Redis::get("refresh:crash_after_write:{$profile->id}") === null,
            'attempt 2 is the same job replayed' => $second !== null && $second->job_uuid === $first?->job_uuid && $second->queue_attempt > $first->queue_attempt,
            'attempt 2 changed nothing (stale_revision)' => $second?->outcome === 'stale_revision',
            'stored data is 121000 / rev 11' => $profile->likes === 121000 && $profile->revision === 11,
        ];

        $this->table(['Check', 'Result'], collect($checks)->map(fn (bool $ok, string $check) => [$check, $ok ? 'PASS' : 'FAIL'])->values()->all());

        if (in_array(false, $checks, true)) {
            $this->warn('Not all checks passed. Is Horizon running with REFRESH_CRASH_INJECTION=true, and was --timeout long enough?');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, RefreshAttempt>  $attempts
     */
    private function printTimeline(Collection $attempts, float $startedAt): void
    {
        $this->table(
            ['#', 'at', 'job_uuid', 'queue_attempt', 'upstream_attempt', 'outcome', 'revision'],
            $attempts->values()->map(fn (RefreshAttempt $attempt, int $index) => [
                $index + 1,
                sprintf('+%ds', $attempt->created_at->getTimestamp() - (int) $startedAt),
                Str::limit($attempt->job_uuid, 8, ''),
                $attempt->queue_attempt,
                $attempt->upstream_attempt,
                $attempt->outcome,
                $attempt->revision,
            ])->all(),
        );
    }
}
