<?php

use App\Models\{Account, Profile};
use Illuminate\Support\Facades\Queue;

it('index page returns 200', function () {
    Account::create([
        'name' => 'Test',
        'credentials' => ['token' => 'test'],
        'max_concurrency' => 2,
    ]);

    Profile::create([
        'account_id' => Account::first()->id,
        'username' => 'test_user',
        'likes' => 100000,
        'revision' => 10,
    ]);

    $this->get('/')->assertStatus(200);
});

it('index page shows profiles', function () {
    $account = Account::create([
        'name' => 'Test',
        'credentials' => ['token' => 'test'],
        'max_concurrency' => 2,
    ]);

    Profile::create([
        'account_id' => $account->id,
        'username' => 'test_user',
        'name' => 'Test User',
        'likes' => 120000,
        'revision' => 10,
    ]);

    $this->get('/')
        ->assertSee('test_user')
        ->assertSee('Test User')
        ->assertSee('120,000');
});

it('search filters by username', function () {
    $account = Account::create([
        'name' => 'Test',
        'credentials' => ['token' => 'test'],
        'max_concurrency' => 2,
    ]);

    Profile::create([
        'account_id' => $account->id,
        'username' => 'alice',
        'likes' => 100000,
        'revision' => 10,
    ]);

    Profile::create([
        'account_id' => $account->id,
        'username' => 'bob',
        'likes' => 50000,
        'revision' => 5,
    ]);

    $this->get('/?q=alice')
        ->assertSee('alice')
        ->assertDontSee('bob');
});

it('refresh dispatches job', function () {
    Queue::fake();

    $account = Account::create([
        'name' => 'Test',
        'credentials' => ['token' => 'test'],
        'max_concurrency' => 2,
    ]);

    $profile = Profile::create([
        'account_id' => $account->id,
        'username' => 'test_user',
        'likes' => 100000,
        'revision' => 10,
    ]);

    $this->post("/profiles/{$profile->id}/refresh")
        ->assertRedirect();

    Queue::assertPushed(\App\Jobs\RefreshProfile::class, 1);
});
