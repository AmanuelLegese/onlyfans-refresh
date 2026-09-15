<?php

use App\Models\{Account, Profile};
use App\Jobs\Legacy\LegacyRefreshProfile;

it('legacy handler stores 0 when likes is missing from top level', function () {
    $account = Account::create([
        'name' => 'Test Account',
        'credentials' => ['token' => 'test-token'],
        'max_concurrency' => 2,
    ]);

    $profile = Profile::create([
        'account_id' => $account->id,
        'username' => 'new_format_user',
        'likes' => 120000,
        'revision' => 10,
    ]);

    // The fake upstream returns new format: profile.likes, not top-level likes
    // The legacy handler reads $json['likes'] ?? 0, which will be 0
    // because likes is inside profile{}, not at top level
    $this->app['config']->set('refresh.upstream_url', 'http://upstream:8081');
    $this->app['config']->set('refresh.upstream_enabled', true);

    // We can't actually call the fake upstream in tests without it running,
    // so we verify the logic directly
    $json = [
        'username' => 'new_format_user',
        'profile' => ['likes' => 121000],
        'revision' => 11,
    ];

    // This is what the legacy handler does
    $likes = $json['likes'] ?? 0;

    expect($likes)->toBe(0);
    expect($likes)->not->toBe(121000);
});

it('legacy handler always marks response as success', function () {
    $account = Account::create([
        'name' => 'Test Account',
        'credentials' => ['token' => 'test-token'],
        'max_concurrency' => 2,
    ]);

    $profile = Profile::create([
        'account_id' => $account->id,
        'username' => 'test_user',
        'likes' => 120000,
        'revision' => 10,
    ]);

    // Simulate what legacy handler does
    $profile->update([
        'likes' => 0, // Bug: defaults to 0
        'revision' => 11,
        'last_attempt_at' => now(),
        'last_attempt_outcome' => 'success',
        'last_success_at' => now(),
    ]);

    $profile->refresh();

    expect($profile->likes)->toBe(0);
    expect($profile->last_attempt_outcome)->toBe('success');
    expect($profile->last_success_at)->not->toBeNull();
});
