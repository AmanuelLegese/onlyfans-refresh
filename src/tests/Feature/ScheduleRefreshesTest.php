<?php

use App\Console\Commands\ScheduleRefreshes;
use App\Jobs\RefreshProfile;
use App\Models\{Account, Profile};
use Illuminate\Support\Facades\{Queue, Artisan};

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
