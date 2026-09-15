<?php

use App\Models\{Account, Profile};

it('creates a profile with correct defaults', function () {
    $account = Account::create([
        'name' => 'Test Account',
        'credentials' => ['token' => 'test-token'],
        'max_concurrency' => 2,
    ]);

    $profile = Profile::create([
        'account_id' => $account->id,
        'username' => 'test_user',
    ]);

    expect($profile->likes)->toBeNull();
    expect($profile->revision)->toBeNull();
    expect($profile->consecutive_failures)->toEqual(0);
    expect($profile->last_attempt_outcome)->toBeNull();
    expect($profile->last_success_at)->toBeNull();
});

it('belongs to an account', function () {
    $account = Account::create([
        'name' => 'Test Account',
        'credentials' => ['token' => 'test-token'],
        'max_concurrency' => 2,
    ]);

    $profile = Profile::create([
        'account_id' => $account->id,
        'username' => 'test_user',
    ]);

    expect($profile->account->id)->toBe($account->id);
});

it('encrypts account credentials', function () {
    $account = Account::create([
        'name' => 'Test Account',
        'credentials' => ['token' => 'secret-token', 'cookie' => 'session-cookie'],
        'max_concurrency' => 2,
    ]);

    $fresh = Account::find($account->id);
    expect($fresh->credentials['token'])->toBe('secret-token');
    expect($fresh->credentials['cookie'])->toBe('session-cookie');
});
