<?php

use App\Jobs\RefreshProfile;
use App\Models\Account;
use App\Models\Profile;
use App\Refresh\ProfileWriter;
use App\Upstream\FakeUpstreamClient;
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
    $job1->handle(app(FakeUpstreamClient::class));

    $profile->refresh();
    expect($profile->revision)->toBe(11);
    expect($profile->last_attempt_outcome)->toBe('success');

    // Second execution (duplicate)
    $job2 = new RefreshProfile($profile->id);
    $job2->handle(app(FakeUpstreamClient::class));

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

    // Another worker won the race and inserted the row first.
    $winner = Profile::create([
        'account_id' => $account->id,
        'username' => 'race_user',
        'likes' => 120000,
        'revision' => 10,
    ]);

    // The losing worker hits the unique index, recovers, and gets the existing row.
    $loser = ProfileWriter::createOrFirst($account, 'race_user');
    $fresh = ProfileWriter::createOrFirst($account, 'brand_new_user');

    expect($loser->id)->toBe($winner->id);
    expect($loser->likes)->toBe(120000);
    expect(Profile::where('username', 'race_user')->count())->toBe(1);
    expect($fresh->wasRecentlyCreated)->toBeTrue();
    expect(Profile::where('username', 'brand_new_user')->count())->toBe(1);
});
