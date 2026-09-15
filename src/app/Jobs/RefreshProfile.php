<?php

namespace App\Jobs;

use App\Jobs\Middleware\{AccountConcurrency, AccountCooldown, RefreshLogContext};
use App\Models\{Account, Profile};
use App\Refresh\{Backoff, ProfilePayload, ProfileWriter, RefreshPolicy};
use App\Refresh\Exceptions\{ClientError, MalformedResponse, RateLimited, ServerError, UpstreamTimeout};
use App\Upstream\FakeUpstreamClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class RefreshProfile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout;
    public int $tries;
    public int $maxExceptions;

    private ?Profile $profile = null;
    private string $mode;
    private int $queueAttempt = 1;
    private int $upstreamAttempt = 1;
    private string $jobUuid;

    public function __construct(
        public int $profileId,
        string $mode = 'fixed',
    ) {
        $this->mode = $mode;
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
        return $this->queueAttempt;
    }

    public function getUpstreamAttempt(): int
    {
        return $this->upstreamAttempt;
    }

    public function middleware(): array
    {
        return [
            new RefreshLogContext(),
            new AccountCooldown(),
            new AccountConcurrency(),
        ];
    }

    public function retryUntil(): \Carbon\Carbon
    {
        return now()->addMinutes(10);
    }

    public function handle(FakeUpstreamClient $client): void
    {
        $profile = $this->getProfile();
        $account = $profile->account;
        $start = microtime(true);

        $this->jobUuid = $this->job?->uuid() ?? Str::uuid()->toString();
        try {
            $this->upstreamAttempt = (int) Redis::incr("refresh:upstream_attempts:{$this->jobUuid}");
            Redis::expire("refresh:upstream_attempts:{$this->jobUuid}", 86400);
        } catch (\Throwable) {
            $this->upstreamAttempt = 1;
        }

        try {
            $response = $client->fetch($account, $profile->username);
        } catch (RateLimited $e) {
            $durationMs = (int) ((microtime(true) - $start) * 1000);

            ProfileWriter::recordFailure(
                profile: $profile,
                account: $account,
                jobUuid: $this->jobUuid,
                outcome: 'rate_limited',
                httpStatus: $e->getCode() ?: 429,
                detail: $e->getMessage(),
                durationMs: $durationMs,
                queueAttempt: $this->queueAttempt,
                upstreamAttempt: $this->upstreamAttempt,
                mode: $this->mode,
            );

            AccountCooldown::setCooldown($account->id, $this->upstreamAttempt);

            $delay = Backoff::fullJitter($this->upstreamAttempt);
            $this->release($delay);
            return;
        } catch (UpstreamTimeout $e) {
            $durationMs = (int) ((microtime(true) - $start) * 1000);

            ProfileWriter::recordFailure(
                profile: $profile,
                account: $account,
                jobUuid: $this->jobUuid,
                outcome: 'timeout',
                httpStatus: null,
                detail: $e->getMessage(),
                durationMs: $durationMs,
                queueAttempt: $this->queueAttempt,
                upstreamAttempt: $this->upstreamAttempt,
                mode: $this->mode,
            );

            $delay = Backoff::fullJitter($this->upstreamAttempt);
            $this->release($delay);
            return;
        } catch (ServerError $e) {
            $durationMs = (int) ((microtime(true) - $start) * 1000);

            ProfileWriter::recordFailure(
                profile: $profile,
                account: $account,
                jobUuid: $this->jobUuid,
                outcome: 'server_error',
                httpStatus: $e->getCode(),
                detail: $e->getMessage(),
                durationMs: $durationMs,
                queueAttempt: $this->queueAttempt,
                upstreamAttempt: $this->upstreamAttempt,
                mode: $this->mode,
            );

            $delay = Backoff::fullJitter($this->upstreamAttempt);
            $this->release($delay);
            return;
        } catch (ClientError $e) {
            $durationMs = (int) ((microtime(true) - $start) * 1000);

            ProfileWriter::recordFailure(
                profile: $profile,
                account: $account,
                jobUuid: $this->jobUuid,
                outcome: 'client_error',
                httpStatus: $e->getCode(),
                detail: $e->getMessage(),
                durationMs: $durationMs,
                queueAttempt: $this->queueAttempt,
                upstreamAttempt: $this->upstreamAttempt,
                mode: $this->mode,
            );

            $this->fail($e);
            return;
        } catch (MalformedResponse $e) {
            $durationMs = (int) ((microtime(true) - $start) * 1000);

            ProfileWriter::recordFailure(
                profile: $profile,
                account: $account,
                jobUuid: $this->jobUuid,
                outcome: 'malformed',
                httpStatus: null,
                detail: $e->getMessage(),
                durationMs: $durationMs,
                queueAttempt: $this->queueAttempt,
                upstreamAttempt: $this->upstreamAttempt,
                mode: $this->mode,
            );

            $this->fail($e);
            return;
        }

        $durationMs = (int) ((microtime(true) - $start) * 1000);
        $payload = ProfilePayload::fromJson($response);

        $outcome = ProfileWriter::applySuccess(
            profile: $profile,
            account: $account,
            payload: $payload,
            jobUuid: $this->jobUuid,
            httpStatus: 200,
            durationMs: $durationMs,
            queueAttempt: $this->queueAttempt,
            upstreamAttempt: $this->upstreamAttempt,
            mode: $this->mode,
        );

        if ($outcome === 'success') {
            AccountCooldown::clearCooldown($account->id);
        }
    }

    public function failed(\Throwable $exception): void
    {
        $profile = Profile::find($this->profileId);
        if ($profile) {
            ProfileWriter::giveUp($profile);
        }
    }
}
