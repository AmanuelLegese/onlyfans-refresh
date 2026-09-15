<?php

namespace App\Jobs;

use App\Jobs\Middleware\AccountConcurrency;
use App\Jobs\Middleware\AccountCooldown;
use App\Jobs\Middleware\RefreshLogContext;
use App\Models\Profile;
use App\Refresh\Backoff;
use App\Refresh\CrashInjector;
use App\Refresh\Exceptions\ClientError;
use App\Refresh\Exceptions\MalformedResponse;
use App\Refresh\Exceptions\RateLimited;
use App\Refresh\Exceptions\ServerError;
use App\Refresh\Exceptions\SignatureRejected;
use App\Refresh\Exceptions\UpstreamException;
use App\Refresh\Exceptions\UpstreamTimeout;
use App\Refresh\ProfilePayload;
use App\Refresh\ProfileWriter;
use App\Upstream\ProfileSource;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class RefreshProfile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Released with backoff until the upstream attempt budget is spent. */
    private const RETRYABLE = [
        RateLimited::class => 'rate_limited',
        UpstreamTimeout::class => 'timeout',
        ServerError::class => 'server_error',
        SignatureRejected::class => 'signature_rejected',
    ];

    /** Recorded and failed without retry. */
    private const PERMANENT = [
        ClientError::class => 'client_error',
        MalformedResponse::class => 'malformed',
    ];

    public int $timeout;

    public int $tries;

    public int $maxExceptions;

    private ?Profile $profile = null;

    private string $mode;

    private int $upstreamAttempt = 0;

    private ?string $jobUuid = null;

    public function __construct(
        public int $profileId,
        string $mode = 'fixed',
    ) {
        $this->mode = $mode;
        $this->onQueue('refresh');
        $this->timeout = config('refresh.job_timeout', 30);
        $this->tries = config('refresh.max_upstream_attempts', 6) + 1;
        $this->maxExceptions = config('refresh.max_upstream_attempts', 6);
    }

    public function getProfile(): Profile
    {
        return $this->profile ??= Profile::findOrFail($this->profileId);
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function getQueueAttempt(): int
    {
        return $this->attempts();
    }

    public function getUpstreamAttempt(): int
    {
        return $this->upstreamAttempt;
    }

    public function jobUuid(): string
    {
        return $this->jobUuid ??= $this->job?->uuid() ?? Str::uuid()->toString();
    }

    /**
     * Writes one JSON line to the refresh log with the account, profile, job and attempt.
     *
     * @param  array<string, mixed>  $context
     */
    public function logEvent(string $event, array $context = [], string $level = 'info'): void
    {
        $profile = $this->getProfile();

        Log::channel('refresh')->log($level, $event, [
            'mode' => $this->mode,
            'account_id' => $profile->account_id,
            'profile_id' => $profile->id,
            'username' => $profile->username,
            'job_uuid' => $this->jobUuid(),
            'queue_attempt' => $this->getQueueAttempt(),
            'upstream_attempt' => $this->upstreamAttempt,
        ] + $context);
    }

    public function middleware(): array
    {
        return [
            new RefreshLogContext,
            new AccountCooldown,
            // One attempt per profile at a time. The lock only expires on its own if a worker died
            // holding it, so expireAfter is longer than the job timeout.
            (new WithoutOverlapping((string) $this->profileId))
                ->releaseAfter(random_int(1, 3))
                ->expireAfter(config('refresh.profile_lock_seconds', 120)),
            new AccountConcurrency,
        ];
    }

    public function retryUntil(): Carbon
    {
        return now()->addMinutes(10);
    }

    public function handle(ProfileSource $client): void
    {
        $profile = $this->getProfile();
        $account = $profile->account;
        $start = microtime(true);

        $this->upstreamAttempt = $this->countUpstreamAttempt();

        try {
            // Validation runs inside the try: an invalid body is recorded as `malformed`.
            $payload = ProfilePayload::fromJson($client->fetch($account, $profile->username));
        } catch (UpstreamException $e) {
            $this->handleFailure($e, $profile, (int) ((microtime(true) - $start) * 1000));

            return;
        }

        $durationMs = (int) ((microtime(true) - $start) * 1000);

        $outcome = ProfileWriter::applySuccess(
            profile: $profile,
            account: $account,
            payload: $payload,
            jobUuid: $this->jobUuid(),
            httpStatus: 200,
            durationMs: $durationMs,
            queueAttempt: $this->getQueueAttempt(),
            upstreamAttempt: $this->upstreamAttempt,
            mode: $this->mode,
        );

        $this->logEvent($outcome === 'success' ? 'refresh.applied' : 'refresh.stale', [
            'previous_revision' => $profile->revision,
            'revision' => $payload->revision,
            'likes' => $payload->likes,
            'duration_ms' => $durationMs,
        ]);

        if ($outcome === 'success') {
            AccountCooldown::clearCooldown($account->id);

            // Local crash test only: the worker dies here, after the write and before the ack.
            app(CrashInjector::class)->crashIfFlagged($profile->id, $this->jobUuid());
        }
    }

    public function failed(\Throwable $exception): void
    {
        $profile = Profile::find($this->profileId);
        if ($profile) {
            ProfileWriter::giveUp($profile);
        }
    }

    private function handleFailure(UpstreamException $e, Profile $profile, int $durationMs): void
    {
        [$outcome, $retryable] = $this->classify($e);
        $httpStatus = $e->getCode() ?: null;

        ProfileWriter::recordFailure(
            profile: $profile,
            account: $profile->account,
            jobUuid: $this->jobUuid(),
            outcome: $outcome,
            httpStatus: $httpStatus,
            detail: $e->getMessage(),
            durationMs: $durationMs,
            queueAttempt: $this->getQueueAttempt(),
            upstreamAttempt: $this->upstreamAttempt,
            mode: $this->mode,
        );

        if ($e instanceof RateLimited) {
            AccountCooldown::setCooldown($profile->account_id, $this->upstreamAttempt);
            $this->logEvent('account.cooldown_set', ['retry_after' => $e->getRetryAfter()], 'warning');
        }

        if ($retryable && $this->upstreamAttempt < config('refresh.max_upstream_attempts', 6)) {
            $delay = Backoff::fullJitter($this->upstreamAttempt);

            if ($e instanceof RateLimited) {
                $delay = max($delay, $e->getRetryAfter());
            }

            $this->logEvent('refresh.released', ['outcome' => $outcome, 'http_status' => $httpStatus, 'delay_seconds' => $delay], 'warning');
            $this->release($delay);

            return;
        }

        $this->logEvent('refresh.failed', [
            'outcome' => $outcome,
            'http_status' => $httpStatus,
            'reason' => $retryable ? 'retry_budget_exhausted' : 'permanent',
            'detail' => $e->getMessage(),
        ], 'error');

        $this->fail($e);
    }

    /**
     * @return array{0: string, 1: bool} outcome and whether it is retryable
     */
    private function classify(UpstreamException $e): array
    {
        foreach (self::RETRYABLE as $class => $outcome) {
            if ($e instanceof $class) {
                return [$outcome, true];
            }
        }

        foreach (self::PERMANENT as $class => $outcome) {
            if ($e instanceof $class) {
                return [$outcome, false];
            }
        }

        return ['upstream_error', false];
    }

    /** Upstream calls made for this job across retries; kept in Redis because a release re-queues the payload. */
    private function countUpstreamAttempt(): int
    {
        $key = "refresh:upstream_attempts:{$this->jobUuid()}";

        try {
            $count = (int) Redis::incr($key);
            Redis::expire($key, 86400);

            return $count;
        } catch (\Throwable) {
            return $this->getQueueAttempt();
        }
    }
}
