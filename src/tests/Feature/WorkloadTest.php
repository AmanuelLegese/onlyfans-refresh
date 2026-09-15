<?php

use App\Jobs\Legacy\LegacyRefreshProfile;
use App\Jobs\RefreshProfile;
use App\Models\Account;
use App\Models\Profile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

it('workload:run dispatches jobs with Queue::fake', function () {
    Queue::fake();

    $this->artisan('workload:run', ['--mode' => 'fixed', '--seed' => '99', '--timeout' => 1])
        ->assertExitCode(0);

    expect(Account::count())->toBeGreaterThanOrEqual(2);

    $accountA = Account::where('name', 'Account A (busy)')->first();
    $accountB = Account::where('name', 'Account B (healthy)')->first();

    expect($accountA)->not->toBeNull();
    expect($accountB)->not->toBeNull();

    expect(Profile::where('account_id', $accountA->id)->count())->toBe(60);
    expect(Profile::where('account_id', $accountB->id)->count())->toBe(10);

    // 70 profiles + 10 duplicates = 80 dispatches
    Queue::assertPushed(RefreshProfile::class, 80);
});

it('workload:run sets Redis scenarios for all profiles', function () {
    Queue::fake();

    $this->artisan('workload:run', ['--mode' => 'fixed', '--seed' => '99', '--timeout' => 1])
        ->assertExitCode(0);

    $allProfiles = Profile::all();
    foreach ($allProfiles as $profile) {
        $scenario = Redis::get("fake:scenario:{$profile->username}");
        expect($scenario)->not->toBeNull();
        $decoded = json_decode($scenario, true);
        expect($decoded)->toHaveKeys(['format', 'p429', 'p500_empty', 'seed']);
    }
});

it('workload:crash-replay flags the profile, dispatches once and fails its checks without a worker', function () {
    Queue::fake();

    $this->artisan('workload:crash-replay', ['--username' => 'crash_test_user', '--timeout' => 1])
        ->assertExitCode(1);

    $profile = Profile::where('username', 'crash_test_user')->firstOrFail();

    Queue::assertPushed(RefreshProfile::class, fn (RefreshProfile $job) => $job->profileId === $profile->id);
    expect(Redis::get("refresh:crash_after_write:{$profile->id}"))->toBe('1');
    expect(json_decode(Redis::get('fake:scenario:crash_test_user'), true))->toMatchArray(['revision' => 11, 'likes' => 121000]);

    Redis::del("refresh:crash_after_write:{$profile->id}");
});

it('workload:run dispatches the legacy handler in legacy mode', function () {
    Queue::fake();

    $this->artisan('workload:run', ['--mode' => 'legacy', '--seed' => '42', '--timeout' => 1])
        ->assertExitCode(0);

    Queue::assertPushed(LegacyRefreshProfile::class, 80);
    Queue::assertNotPushed(RefreshProfile::class);
    Queue::assertPushedOn('refresh', LegacyRefreshProfile::class);
});

it('workload:run rejects an unknown mode', function () {
    Queue::fake();

    $this->artisan('workload:run', ['--mode' => 'broken', '--timeout' => 1])
        ->assertExitCode(1);

    Queue::assertNothingPushed();
});
