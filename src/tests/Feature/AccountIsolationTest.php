<?php

use App\Jobs\Middleware\AccountConcurrency;
use App\Jobs\RefreshProfile;
use App\Models\Account;
use App\Models\Profile;
use App\Upstream\FakeUpstreamClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

it('cooldown on account A does not affect account B', function () {
    $accountA = Account::create([
        'name' => 'Account A',
        'credentials' => ['token' => 'token-a'],
        'max_concurrency' => 2,
    ]);

    $accountB = Account::create([
        'name' => 'Account B',
        'credentials' => ['token' => 'token-b'],
        'max_concurrency' => 2,
    ]);

    $profileA = Profile::create([
        'account_id' => $accountA->id,
        'username' => 'user_a',
        'likes' => 120000,
        'revision' => 10,
    ]);

    $profileB = Profile::create([
        'account_id' => $accountB->id,
        'username' => 'user_b',
        'likes' => 50000,
        'revision' => 5,
    ]);

    // Set cooldown for account A
    Redis::set("refresh:cooldown:account:{$accountA->id}", now()->addSeconds(30)->timestamp, 'EX', 60);

    // Account B should not have a cooldown key
    $hasCooldownB = Redis::exists("refresh:cooldown:account:{$accountB->id}");
    expect((bool) $hasCooldownB)->toBeFalse();
});

it('concurrency limit releases extra jobs', function () {
    $account = Account::create([
        'name' => 'Test Account',
        'credentials' => ['token' => 'test-token'],
        'max_concurrency' => 1,
    ]);

    $profile = Profile::create([
        'account_id' => $account->id,
        'username' => 'concurrency_user',
        'likes' => 120000,
        'revision' => 10,
    ]);

    $job = (new RefreshProfile($profile->id))->withFakeQueueInteractions();
    $nextCalled = false;

    // Hold the account's only slot, as a running job would, and try a second job meanwhile.
    Redis::funnel("refresh:concurrency:account:{$account->id}")
        ->limit(1)
        ->releaseAfter(60)
        ->block(0)
        ->then(function () use ($job, &$nextCalled) {
            (new AccountConcurrency)->handle($job, function () use (&$nextCalled) {
                $nextCalled = true;
            });
        });

    expect($nextCalled)->toBeFalse();
    $job->assertReleased();

    // Once the slot is free, the job runs.
    $next = (new RefreshProfile($profile->id))->withFakeQueueInteractions();
    (new AccountConcurrency)->handle($next, function () use (&$nextCalled) {
        $nextCalled = true;
    });

    expect($nextCalled)->toBeTrue();
    $next->assertNotReleased();
});

it('account B continues when account A is rate limited', function () {
    $accountA = Account::create([
        'name' => 'Account A',
        'credentials' => ['token' => 'token-a'],
        'max_concurrency' => 2,
    ]);

    $accountB = Account::create([
        'name' => 'Account B',
        'credentials' => ['token' => 'token-b'],
        'max_concurrency' => 2,
    ]);

    $profileA = Profile::create([
        'account_id' => $accountA->id,
        'username' => 'user_a',
        'likes' => 120000,
        'revision' => 10,
    ]);

    $profileB = Profile::create([
        'account_id' => $accountB->id,
        'username' => 'user_b',
        'likes' => 50000,
        'revision' => 5,
    ]);

    // Account A gets rate limited
    Http::fake([
        '*/user_a' => Http::response(['error' => 'Too Many Requests'], 429),
        '*/user_b' => Http::response([
            'username' => 'user_b',
            'likes' => 51000,
            'revision' => 6,
        ], 200),
    ]);

    // Account A: rate limited
    $jobA = new RefreshProfile($profileA->id);
    try {
        $jobA->handle(app(FakeUpstreamClient::class));
    } catch (Throwable $e) {
        // release() throws outside queue worker
    }

    // Account B: should succeed
    $jobB = new RefreshProfile($profileB->id);
    $jobB->handle(app(FakeUpstreamClient::class));

    $profileA->refresh();
    $profileB->refresh();

    expect($profileA->last_attempt_outcome)->toBe('rate_limited');
    expect($profileB->last_attempt_outcome)->toBe('success');
    expect($profileB->likes)->toBe(51000);
    expect($profileB->revision)->toBe(6);
});

it('concurrent jobs for different accounts run independently', function () {
    $accountA = Account::create([
        'name' => 'Account A',
        'credentials' => ['token' => 'token-a'],
        'max_concurrency' => 2,
    ]);

    $accountB = Account::create([
        'name' => 'Account B',
        'credentials' => ['token' => 'token-b'],
        'max_concurrency' => 2,
    ]);

    $profileA = Profile::create([
        'account_id' => $accountA->id,
        'username' => 'concurrent_a',
        'likes' => 120000,
        'revision' => 10,
    ]);

    $profileB = Profile::create([
        'account_id' => $accountB->id,
        'username' => 'concurrent_b',
        'likes' => 50000,
        'revision' => 5,
    ]);

    Http::fake([
        '*/concurrent_a' => Http::response([
            'username' => 'concurrent_a',
            'likes' => 121000,
            'revision' => 11,
        ], 200),
        '*/concurrent_b' => Http::response([
            'username' => 'concurrent_b',
            'likes' => 51000,
            'revision' => 6,
        ], 200),
    ]);

    $jobA = new RefreshProfile($profileA->id);
    $jobA->handle(app(FakeUpstreamClient::class));

    $jobB = new RefreshProfile($profileB->id);
    $jobB->handle(app(FakeUpstreamClient::class));

    $profileA->refresh();
    $profileB->refresh();

    expect($profileA->last_attempt_outcome)->toBe('success');
    expect($profileA->likes)->toBe(121000);
    expect($profileB->last_attempt_outcome)->toBe('success');
    expect($profileB->likes)->toBe(51000);
});
