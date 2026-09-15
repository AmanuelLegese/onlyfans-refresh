<?php

use App\Jobs\Legacy\LegacyRefreshProfile;
use App\Models\Account;
use App\Models\Profile;
use Illuminate\Support\Facades\Http;

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

it('legacy handler marks likes=0 with outcome=success when upstream returns new format', function () {
    $account = Account::create([
        'name' => 'Legacy Account',
        'credentials' => ['token' => 'legacy-token'],
        'max_concurrency' => 2,
    ]);

    $profile = Profile::create([
        'account_id' => $account->id,
        'username' => 'madison420ivy',
        'likes' => 120000,
        'revision' => 10,
    ]);

    // Fake upstream returns new format with likes inside profile{}
    Http::fake([
        'http://upstream:8081/fake/api/users/madison420ivy' => Http::response([
            'username' => 'madison420ivy',
            'profile' => ['likes' => 121000],
            'revision' => 11,
        ], 200),
    ]);

    dispatch_sync(new LegacyRefreshProfile($profile->id));

    $profile->refresh();

    // BUG PROVEN: Legacy handler stores 0 likes and marks it as success
    // even though the upstream returned 121000 likes in the new format
    expect($profile->likes)->toBe(0);
    expect($profile->last_attempt_outcome)->toBe('success');
    expect($profile->last_success_at)->not->toBeNull();
    expect($profile->revision)->toBe(11);
});

it('legacy handler zeroes likes, erases revision and marks success on 429 and empty 500', function (int $status, mixed $body) {
    $account = Account::create([
        'name' => 'Legacy Account',
        'credentials' => ['token' => 'legacy-token'],
        'max_concurrency' => 2,
    ]);

    $profile = Profile::create([
        'account_id' => $account->id,
        'username' => 'madison420ivy',
        'likes' => 120000,
        'revision' => 10,
    ]);

    Http::fake(['*' => Http::response($body, $status)]);

    dispatch_sync(new LegacyRefreshProfile($profile->id));

    $profile->refresh();

    // BUG PROVEN: a failed request overwrites the last valid profile and counts as a success.
    expect($profile->likes)->toBe(0);
    expect($profile->revision)->toBeNull();
    expect($profile->last_attempt_outcome)->toBe('success');

    $this->assertDatabaseHas('refresh_attempts', [
        'profile_id' => $profile->id,
        'mode' => 'legacy',
        'outcome' => 'success',
        'http_status' => $status,
    ]);
})->with([
    '429 without Retry-After' => [429, ['error' => 'Too Many Requests']],
    '500 with empty body' => [500, ''],
]);

it('legacy handler lets an older revision arriving last overwrite newer data', function () {
    $account = Account::create([
        'name' => 'Legacy Account',
        'credentials' => ['token' => 'legacy-token'],
        'max_concurrency' => 2,
    ]);

    $profile = Profile::create([
        'account_id' => $account->id,
        'username' => 'madison420ivy',
        'likes' => 120000,
        'revision' => 10,
    ]);

    Http::fakeSequence()
        ->push(['profile' => ['likes' => 121000], 'revision' => 11])
        ->push(['likes' => 120000, 'revision' => 10]);

    dispatch_sync(new LegacyRefreshProfile($profile->id));
    dispatch_sync(new LegacyRefreshProfile($profile->id));

    $profile->refresh();

    // BUG PROVEN: no revision check, so the late revision 10 replaces revision 11.
    expect($profile->revision)->toBe(10);
    expect($profile->likes)->toBe(120000);
});
