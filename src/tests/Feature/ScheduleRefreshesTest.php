<?php

use App\Jobs\RefreshProfile;
use App\Models\Account;
use App\Models\Profile;
use App\Refresh\RefreshDispatcher;
use App\Upstream\FakeUpstreamClient;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

it('dispatches refresh for due profiles', function () {
    Queue::fake();

    $account = Account::create([
        'name' => 'Test Account',
        'credentials' => ['token' => 'test-token'],
        'max_concurrency' => 2,
    ]);

    Profile::create([
        'account_id' => $account->id,
        'username' => 'due_user',
        'likes' => 120000,
        'revision' => 10,
        'next_refresh_at' => now()->subMinute(),
    ]);

    Artisan::call('profiles:schedule-refreshes');

    Queue::assertPushed(RefreshProfile::class, 1);
});

it('does not dispatch for profiles not yet due', function () {
    Queue::fake();

    $account = Account::create([
        'name' => 'Test Account',
        'credentials' => ['token' => 'test-token'],
        'max_concurrency' => 2,
    ]);

    Profile::create([
        'account_id' => $account->id,
        'username' => 'not_due_user',
        'likes' => 120000,
        'revision' => 10,
        'next_refresh_at' => now()->addHours(24),
    ]);

    Artisan::call('profiles:schedule-refreshes');

    Queue::assertNothingPushed();
});

it('does not dispatch for profiles already queued', function () {
    Queue::fake();

    $account = Account::create([
        'name' => 'Test Account',
        'credentials' => ['token' => 'test-token'],
        'max_concurrency' => 2,
    ]);

    Profile::create([
        'account_id' => $account->id,
        'username' => 'queued_user',
        'likes' => 120000,
        'revision' => 10,
        'next_refresh_at' => now()->subMinute(),
        'refresh_queued_at' => now(),
    ]);

    Artisan::call('profiles:schedule-refreshes');

    Queue::assertNothingPushed();
});

it('reclaims stale queued profiles', function () {
    Queue::fake();

    $account = Account::create([
        'name' => 'Test Account',
        'credentials' => ['token' => 'test-token'],
        'max_concurrency' => 2,
    ]);

    Profile::create([
        'account_id' => $account->id,
        'username' => 'stale_queued_user',
        'likes' => 120000,
        'revision' => 10,
        'next_refresh_at' => now()->subMinute(),
        'refresh_queued_at' => now()->subHours(2),
    ]);

    Artisan::call('profiles:schedule-refreshes');

    Queue::assertPushed(RefreshProfile::class, 1);
});

it('dispatches multiple due profiles', function () {
    Queue::fake();

    $account = Account::create([
        'name' => 'Test Account',
        'credentials' => ['token' => 'test-token'],
        'max_concurrency' => 2,
    ]);

    Profile::create([
        'account_id' => $account->id,
        'username' => 'due_user_1',
        'likes' => 120000,
        'revision' => 10,
        'next_refresh_at' => now()->subMinute(),
    ]);

    Profile::create([
        'account_id' => $account->id,
        'username' => 'due_user_2',
        'likes' => 50000,
        'revision' => 5,
        'next_refresh_at' => now()->subHour(),
    ]);

    Artisan::call('profiles:schedule-refreshes');

    Queue::assertPushed(RefreshProfile::class, 2);
});

it('respects refresh_queued_at within 1 hour', function () {
    Queue::fake();

    $account = Account::create([
        'name' => 'Test Account',
        'credentials' => ['token' => 'test-token'],
        'max_concurrency' => 2,
    ]);

    Profile::create([
        'account_id' => $account->id,
        'username' => 'recently_queued_user',
        'likes' => 120000,
        'revision' => 10,
        'next_refresh_at' => now()->subMinute(),
        'refresh_queued_at' => now()->subMinutes(30),
    ]);

    Artisan::call('profiles:schedule-refreshes');

    Queue::assertNothingPushed();
});

it('dispatches profiles that have never been refreshed', function () {
    Queue::fake();

    $account = Account::create([
        'name' => 'Test Account',
        'credentials' => ['token' => 'test-token'],
        'max_concurrency' => 2,
    ]);

    $profile = Profile::create([
        'account_id' => $account->id,
        'username' => 'never_refreshed_user',
    ]);

    Artisan::call('profiles:schedule-refreshes');

    Queue::assertPushed(RefreshProfile::class, fn (RefreshProfile $job) => $job->profileId === $profile->id);
    expect($profile->refresh()->refresh_queued_at)->not->toBeNull();
});

it('clears the pending claim after a successful refresh so the next refresh can be queued', function () {
    $account = Account::create([
        'name' => 'Test Account',
        'credentials' => ['token' => 'test-token'],
        'max_concurrency' => 2,
    ]);

    $profile = Profile::create([
        'account_id' => $account->id,
        'username' => 'claimed_user',
        'likes' => 120000,
        'revision' => 10,
        'refresh_queued_at' => now(),
    ]);

    Http::fake(['*' => Http::response(['profile' => ['likes' => 121000], 'revision' => 11])]);

    (new RefreshProfile($profile->id))->handle(app(FakeUpstreamClient::class));

    expect($profile->refresh()->refresh_queued_at)->toBeNull();
    $this->assertDatabaseHas('refresh_attempts', ['profile_id' => $profile->id, 'outcome' => 'success']);

    Queue::fake();
    expect(RefreshDispatcher::dispatchIfNotPending($profile))->toBeTrue();
    Queue::assertPushed(RefreshProfile::class, 1);
});
