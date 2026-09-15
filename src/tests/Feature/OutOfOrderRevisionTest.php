<?php

use App\Jobs\RefreshProfile;
use App\Models\{Account, Profile};
use Illuminate\Support\Facades\{Http, Redis};

it('applies rev 11 then rev 10 → still rev 11 with stale_revision', function () {
    $account = Account::create([
        'name' => 'Test Account',
        'credentials' => ['token' => 'test-token'],
        'max_concurrency' => 2,
    ]);

    $profile = Profile::create([
        'account_id' => $account->id,
        'username' => 'out_of_order_user',
        'likes' => 120000,
        'revision' => 10,
    ]);

    // First request: rev 11
    Http::fake([
        '*' => Http::response([
            'username' => 'out_of_order_user',
            'likes' => 121000,
            'revision' => 11,
        ], 200),
    ]);

    $job1 = new RefreshProfile($profile->id);
    $job1->handle(app(\App\Upstream\FakeUpstreamClient::class));

    $profile->refresh();
    expect($profile->likes)->toBe(121000);
    expect($profile->revision)->toBe(11);
    expect($profile->last_attempt_outcome)->toBe('success');

    // Second request: rev 10 (out of order, arrives late)
    Http::fake([
        '*' => Http::response([
            'username' => 'out_of_order_user',
            'likes' => 120000,
            'revision' => 10,
        ], 200),
    ]);

    $job2 = new RefreshProfile($profile->id);
    $job2->handle(app(\App\Upstream\FakeUpstreamClient::class));

    $profile->refresh();
    expect($profile->likes)->toBe(121000);
    expect($profile->revision)->toBe(11);
    expect($profile->last_attempt_outcome)->toBe('stale_revision');
});

it('applies rev 11 twice → second is stale', function () {
    $account = Account::create([
        'name' => 'Test Account',
        'credentials' => ['token' => 'test-token'],
        'max_concurrency' => 2,
    ]);

    $profile = Profile::create([
        'account_id' => $account->id,
        'username' => 'duplicate_rev_user',
        'likes' => 120000,
        'revision' => 10,
    ]);

    Http::fake([
        '*' => Http::response([
            'username' => 'duplicate_rev_user',
            'likes' => 121000,
            'revision' => 11,
        ], 200),
    ]);

    $job1 = new RefreshProfile($profile->id);
    $job1->handle(app(\App\Upstream\FakeUpstreamClient::class));

    $profile->refresh();
    expect($profile->revision)->toBe(11);
    expect($profile->last_attempt_outcome)->toBe('success');

    // Same revision again
    $job2 = new RefreshProfile($profile->id);
    $job2->handle(app(\App\Upstream\FakeUpstreamClient::class));

    $profile->refresh();
    expect($profile->revision)->toBe(11);
    expect($profile->last_attempt_outcome)->toBe('stale_revision');
});
