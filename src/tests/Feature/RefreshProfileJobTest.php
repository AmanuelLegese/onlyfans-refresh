<?php

use App\Jobs\RefreshProfile;
use App\Models\{Account, Profile, RefreshAttempt};
use App\Refresh\{ProfilePayload, ProfileWriter, RefreshPolicy};
use Illuminate\Support\Facades\Http;

it('applies new format data and stores correct likes/revision', function () {
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

    Http::fake([
        '*' => Http::response([
            'username' => 'new_format_user',
            'profile' => ['likes' => 121000],
            'revision' => 11,
            'name' => 'New Format User',
            'avatar_url' => 'https://example.com/new.jpg',
            'posts_count' => 100,
            'photos_count' => 50,
            'videos_count' => 10,
        ], 200),
    ]);

    $job = new RefreshProfile($profile->id);
    $job->handle(app(\App\Upstream\FakeUpstreamClient::class));

    $profile->refresh();

    expect($profile->likes)->toBe(121000);
    expect($profile->revision)->toBe(11);
    expect($profile->name)->toBe('New Format User');
    expect($profile->last_attempt_outcome)->toBe('success');
    expect($profile->last_success_at)->not->toBeNull();
    expect($profile->consecutive_failures)->toEqual(0);

    $this->assertDatabaseHas('refresh_attempts', [
        'profile_id' => $profile->id,
        'outcome' => 'success',
        'http_status' => 200,
        'revision' => 11,
    ]);
});

it('does not overwrite valid data with stale revision', function () {
    $account = Account::create([
        'name' => 'Test Account',
        'credentials' => ['token' => 'test-token'],
        'max_concurrency' => 2,
    ]);

    $profile = Profile::create([
        'account_id' => $account->id,
        'username' => 'stale_user',
        'likes' => 121000,
        'revision' => 11,
        'last_success_at' => now(),
    ]);

    Http::fake([
        '*' => Http::response([
            'username' => 'stale_user',
            'likes' => 120000,
            'revision' => 10,
        ], 200),
    ]);

    $job = new RefreshProfile($profile->id);
    $job->handle(app(\App\Upstream\FakeUpstreamClient::class));

    $profile->refresh();

    expect($profile->likes)->toBe(121000);
    expect($profile->revision)->toBe(11);
    expect($profile->last_attempt_outcome)->toBe('stale_revision');
});

it('records rate_limited and keeps data intact', function () {
    $account = Account::create([
        'name' => 'Test Account',
        'credentials' => ['token' => 'test-token'],
        'max_concurrency' => 2,
    ]);

    $profile = Profile::create([
        'account_id' => $account->id,
        'username' => 'rate_limited_user',
        'likes' => 120000,
        'revision' => 10,
    ]);

    Http::fake([
        '*' => Http::response(['error' => 'Too Many Requests'], 429),
    ]);

    $job = new RefreshProfile($profile->id);

    try {
        $job->handle(app(\App\Upstream\FakeUpstreamClient::class));
    } catch (\Throwable $e) {
        // release() throws when not in a queue worker context
    }

    $profile->refresh();

    expect($profile->likes)->toBe(120000);
    expect($profile->revision)->toBe(10);
    expect($profile->last_attempt_outcome)->toBe('rate_limited');

    $this->assertDatabaseHas('refresh_attempts', [
        'profile_id' => $profile->id,
        'outcome' => 'rate_limited',
    ]);
});

it('records server_error and keeps data intact', function () {
    $account = Account::create([
        'name' => 'Test Account',
        'credentials' => ['token' => 'test-token'],
        'max_concurrency' => 2,
    ]);

    $profile = Profile::create([
        'account_id' => $account->id,
        'username' => 'server_error_user',
        'likes' => 120000,
        'revision' => 10,
    ]);

    Http::fake([
        '*' => Http::response([], 500),
    ]);

    $job = new RefreshProfile($profile->id);

    try {
        $job->handle(app(\App\Upstream\FakeUpstreamClient::class));
    } catch (\Throwable $e) {
        // release() throws when not in a queue worker context
    }

    $profile->refresh();

    expect($profile->likes)->toBe(120000);
    expect($profile->revision)->toBe(10);
    expect($profile->last_attempt_outcome)->toBe('server_error');

    $this->assertDatabaseHas('refresh_attempts', [
        'profile_id' => $profile->id,
        'outcome' => 'server_error',
    ]);
});

it('fails on client_error', function () {
    $account = Account::create([
        'name' => 'Test Account',
        'credentials' => ['token' => 'test-token'],
        'max_concurrency' => 2,
    ]);

    $profile = Profile::create([
        'account_id' => $account->id,
        'username' => 'client_error_user',
        'likes' => 120000,
        'revision' => 10,
    ]);

    Http::fake([
        '*' => Http::response(['error' => 'Not Found'], 404),
    ]);

    $job = new RefreshProfile($profile->id);
    $job->handle(app(\App\Upstream\FakeUpstreamClient::class));

    $profile->refresh();

    expect($profile->last_attempt_outcome)->toBe('client_error');
    expect($profile->last_failure_reason)->toBe('client_error');

    $this->assertDatabaseHas('refresh_attempts', [
        'profile_id' => $profile->id,
        'outcome' => 'client_error',
        'http_status' => 404,
    ]);
});

it('fails on malformed response', function () {
    $account = Account::create([
        'name' => 'Test Account',
        'credentials' => ['token' => 'test-token'],
        'max_concurrency' => 2,
    ]);

    $profile = Profile::create([
        'account_id' => $account->id,
        'username' => 'malformed_user',
        'likes' => 120000,
        'revision' => 10,
    ]);

    Http::fake([
        '*' => Http::response('not json', 200),
    ]);

    $job = new RefreshProfile($profile->id);
    $job->handle(app(\App\Upstream\FakeUpstreamClient::class));

    $profile->refresh();

    expect($profile->last_attempt_outcome)->toBe('malformed');

    $this->assertDatabaseHas('refresh_attempts', [
        'profile_id' => $profile->id,
        'outcome' => 'malformed',
    ]);
});

it('sets next_refresh_at based on likes count', function () {
    $nextRefresh = RefreshPolicy::nextRefreshAt(121000);

    expect($nextRefresh->timestamp)->toBeGreaterThan(now()->addHours(23)->timestamp);
    expect($nextRefresh->timestamp)->toBeLessThanOrEqual(now()->addHours(25)->timestamp);
});

it('giveUp clears refresh_queued_at and pushes next_refresh_at', function () {
    $account = Account::create([
        'name' => 'Test Account',
        'credentials' => ['token' => 'test-token'],
        'max_concurrency' => 2,
    ]);

    $profile = Profile::create([
        'account_id' => $account->id,
        'username' => 'giveup_user',
        'likes' => 120000,
        'revision' => 10,
        'refresh_queued_at' => now(),
        'consecutive_failures' => 2,
    ]);

    ProfileWriter::giveUp($profile);

    $profile->refresh();

    expect($profile->refresh_queued_at)->toBeNull();
    expect($profile->next_refresh_at->timestamp)->toBeGreaterThan(now()->addMinutes(19)->timestamp);
    expect($profile->next_refresh_at->timestamp)->toBeLessThanOrEqual(now()->addMinutes(21)->timestamp);
    expect($profile->consecutive_failures)->toEqual(3);
});
