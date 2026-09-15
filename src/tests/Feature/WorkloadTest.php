<?php

use App\Models\{Account, Profile, RefreshAttempt};
use App\Jobs\RefreshProfile;
use Illuminate\Support\Facades\{Redis, Queue};

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

it('workload:crash-replay creates profile and dispatches', function () {
    Queue::fake();

    $profile = Profile::create([
        'account_id' => Account::create([
            'name' => 'Crash Test',
            'credentials' => ['token' => 'crash'],
            'max_concurrency' => 1,
        ])->id,
        'username' => 'crash_test_user',
        'likes' => 120000,
        'revision' => 10,
        'next_refresh_at' => now(),
    ]);

    Redis::set("crash:flag:{$profile->id}", '1');

    $scenario = [
        'format' => 'new',
        'rate_limit_until' => 0,
        'p429' => 0,
        'p500_empty' => 0,
        'p_slow' => 0,
        'slow_ms' => 100,
        'latency_ms' => 100,
        'seed' => 'crash-crash_test_user',
        'revision_mode' => 'static',
        'revision' => 11,
        'likes' => 121000,
    ];
    Redis::set("fake:scenario:crash_test_user", json_encode($scenario));

    $this->artisan('workload:crash-replay', ['--username' => 'crash_test_user', '--timeout' => 1])
        ->assertExitCode(0);

    Queue::assertPushed(RefreshProfile::class, 1);
});

it('workload:run dispatches in legacy mode', function () {
    Queue::fake();

    $this->artisan('workload:run', ['--mode' => 'legacy', '--seed' => '42', '--timeout' => 1])
        ->assertExitCode(0);

    Queue::assertPushed(RefreshProfile::class, function ($job) {
        return $job->getMode() === 'legacy';
    });
});
