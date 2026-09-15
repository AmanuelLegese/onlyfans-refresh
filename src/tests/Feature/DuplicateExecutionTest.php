<?php

use App\Jobs\RefreshProfile;
use App\Models\{Account, Profile};
use Illuminate\Support\Facades\Http;

it('same job handled twice produces one success and one stale row', function () {
    $account = Account::create([
        'name' => 'Test Account',
        'credentials' => ['token' => 'test-token'],
        'max_concurrency' => 2,
    ]);

    $profile = Profile::create([
        'account_id' => $account->id,
        'username' => 'duplicate_job_user',
        'likes' => 120000,
        'revision' => 10,
    ]);

    Http::fake([
        '*' => Http::response([
            'username' => 'duplicate_job_user',
            'likes' => 121000,
            'revision' => 11,
        ], 200),
    ]);

    // First execution
    $job1 = new RefreshProfile($profile->id);
    $job1->handle(app(\App\Upstream\FakeUpstreamClient::class));

    $profile->refresh();
    expect($profile->revision)->toBe(11);
    expect($profile->last_attempt_outcome)->toBe('success');

    // Second execution (duplicate)
    $job2 = new RefreshProfile($profile->id);
    $job2->handle(app(\App\Upstream\FakeUpstreamClient::class));

    $profile->refresh();
    expect($profile->revision)->toBe(11);
    expect($profile->last_attempt_outcome)->toBe('stale_revision');

    $this->assertDatabaseHas('refresh_attempts', [
        'profile_id' => $profile->id,
        'outcome' => 'success',
        'revision' => 11,
    ]);

    $this->assertDatabaseHas('refresh_attempts', [
        'profile_id' => $profile->id,
        'outcome' => 'stale_revision',
        'revision' => 11,
    ]);
});

it('createOrFirst race produces one profile row', function () {
    $account = Account::create([
        'name' => 'Test Account',
        'credentials' => ['token' => 'test-token'],
        'max_concurrency' => 2,
    ]);

    // Simulate race: both try to create the same username
    $profile1 = Profile::create([
        'account_id' => $account->id,
        'username' => 'race_user',
        'likes' => 120000,
        'revision' => 10,
    ]);

    // Second attempt should find existing, not create duplicate
    $existing = Profile::where('username', 'race_user')->first();
    expect($existing->id)->toBe($profile1->id);

    $count = Profile::where('username', 'race_user')->count();
    expect($count)->toBe(1);
});
