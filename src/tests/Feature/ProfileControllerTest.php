<?php

use App\Jobs\RefreshProfile;
use App\Models\Account;
use App\Models\Profile;
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

    Queue::assertPushed(RefreshProfile::class, 1);
});

it('refresh does not queue a second job while one is pending', function () {
    Queue::fake();

    $account = Account::create([
        'name' => 'Test',
        'credentials' => ['token' => 'test'],
        'max_concurrency' => 2,
    ]);

    $profile = Profile::create([
        'account_id' => $account->id,
        'username' => 'pending_user',
        'likes' => 100000,
        'revision' => 10,
    ]);

    $this->post("/profiles/{$profile->id}/refresh")->assertRedirect()->assertSessionHas('status', 'Refresh dispatched for @pending_user');
    $this->post("/profiles/{$profile->id}/refresh")->assertRedirect()->assertSessionHas('status', 'A refresh is already pending for @pending_user');

    Queue::assertPushed(RefreshProfile::class, 1);
});

it('search is case-insensitive through Scout', function () {
    $account = Account::create([
        'name' => 'Test',
        'credentials' => ['token' => 'test'],
        'max_concurrency' => 2,
    ]);

    Profile::create(['account_id' => $account->id, 'username' => 'madison420ivy', 'name' => 'Madison Ivy']);
    Profile::create(['account_id' => $account->id, 'username' => 'someone_else', 'name' => 'Other']);

    $this->get('/?q=MADISON')
        ->assertOk()
        ->assertSee('madison420ivy')
        ->assertDontSee('someone_else');
});
