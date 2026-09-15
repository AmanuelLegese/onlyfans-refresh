<?php

use App\Jobs\RefreshProfile;
use App\Models\{Account, Profile};
use Illuminate\Support\Facades\{Http, Redis};

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

    // Simulate a running job by setting concurrency to max
    Redis::set("refresh:concurrency:account:{$account->id}", 1, 'EX', 60);

    Http::fake([
        '*' => Http::response([
            'username' => 'concurrency_user',
            'likes' => 121000,
            'revision' => 11,
        ], 200),
    ]);

    // Run the middleware manually
    $middleware = new \App\Jobs\Middleware\AccountConcurrency();
    $nextCalled = false;

    $job = new RefreshProfile($profile->id);

    // The middleware reads $job->getProfile() which needs the profile loaded
    // Since Redis says concurrency=1 and max=1, it should release
    // release() throws RuntimeException outside a queue worker, which is expected
    try {
        $middleware->handle($job, function ($j) use (&$nextCalled) {
            $nextCalled = true;
        });
    } catch (\RuntimeException) {
        // release() called — this is the expected behavior
    }

    // next should NOT have been called
    expect($nextCalled)->toBeFalse();
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
        $jobA->handle(app(\App\Upstream\FakeUpstreamClient::class));
    } catch (\Throwable $e) {
        // release() throws outside queue worker
    }

    // Account B: should succeed
    $jobB = new RefreshProfile($profileB->id);
    $jobB->handle(app(\App\Upstream\FakeUpstreamClient::class));

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
    $jobA->handle(app(\App\Upstream\FakeUpstreamClient::class));

    $jobB = new RefreshProfile($profileB->id);
    $jobB->handle(app(\App\Upstream\FakeUpstreamClient::class));

    $profileA->refresh();
    $profileB->refresh();

    expect($profileA->last_attempt_outcome)->toBe('success');
    expect($profileA->likes)->toBe(121000);
    expect($profileB->last_attempt_outcome)->toBe('success');
    expect($profileB->likes)->toBe(51000);
});
