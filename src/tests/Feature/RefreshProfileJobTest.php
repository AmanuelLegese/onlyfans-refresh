<?php

use App\Jobs\RefreshProfile;
use App\Models\Account;
use App\Models\Profile;
use App\Refresh\CrashInjector;
use App\Refresh\ProfileWriter;
use App\Refresh\RefreshPolicy;
use App\Upstream\FakeUpstreamClient;
use Illuminate\Http\Client\ConnectionException;
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
    $job->handle(app(FakeUpstreamClient::class));

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
    $job->handle(app(FakeUpstreamClient::class));

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
        $job->handle(app(FakeUpstreamClient::class));
    } catch (Throwable $e) {
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
        $job->handle(app(FakeUpstreamClient::class));
    } catch (Throwable $e) {
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
    $job->handle(app(FakeUpstreamClient::class));

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
    $job->handle(app(FakeUpstreamClient::class));

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
    // recordFailure() counts failures; giveUp() only schedules the backoff.
    expect($profile->consecutive_failures)->toEqual(2);
});

it('records a JSON body with invalid likes or revision as malformed without touching stored data', function (string $fixture) {
    $account = Account::create([
        'name' => 'Test Account',
        'credentials' => ['token' => 'test-token'],
        'max_concurrency' => 2,
    ]);

    $lastSuccessAt = now()->subDay()->startOfSecond();

    $profile = Profile::create([
        'account_id' => $account->id,
        'username' => 'madison420ivy',
        'likes' => 120000,
        'revision' => 10,
        'last_success_at' => $lastSuccessAt,
    ]);

    Http::fake([
        '*' => Http::response(json_decode(file_get_contents(base_path("tests/Fixtures/upstream/{$fixture}.json")), true), 200),
    ]);

    $job = new RefreshProfile($profile->id);
    $job->handle(app(FakeUpstreamClient::class));

    $profile->refresh();

    expect($profile->likes)->toBe(120000);
    expect($profile->revision)->toBe(10);
    expect($profile->last_success_at->equalTo($lastSuccessAt))->toBeTrue();
    expect($profile->last_attempt_outcome)->toBe('malformed');
    expect($profile->last_failure_reason)->toBe('malformed');

    $this->assertDatabaseHas('refresh_attempts', [
        'profile_id' => $profile->id,
        'outcome' => 'malformed',
    ]);
})->with(['missing-likes', 'negative-likes', 'string-likes', 'string-number-likes', 'float-likes', 'missing-revision']);

it('releases a timed-out request with backoff and keeps stored data', function () {
    $account = Account::create([
        'name' => 'Test Account',
        'credentials' => ['token' => 'test-token'],
        'max_concurrency' => 2,
    ]);

    $profile = Profile::create([
        'account_id' => $account->id,
        'username' => 'timeout_user',
        'likes' => 120000,
        'revision' => 10,
    ]);

    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

    $job = (new RefreshProfile($profile->id))->withFakeQueueInteractions();
    $job->handle(app(FakeUpstreamClient::class));

    $profile->refresh();

    expect($profile->likes)->toBe(120000);
    expect($profile->revision)->toBe(10);
    expect($profile->last_attempt_outcome)->toBe('timeout');
    $job->assertReleased();
    $job->assertNotFailed();

    $this->assertDatabaseHas('refresh_attempts', [
        'profile_id' => $profile->id,
        'outcome' => 'timeout',
        'http_status' => null,
    ]);
});

it('fails instead of releasing once the upstream attempt budget is spent', function () {
    config(['refresh.max_upstream_attempts' => 1]);

    $account = Account::create([
        'name' => 'Test Account',
        'credentials' => ['token' => 'test-token'],
        'max_concurrency' => 2,
    ]);

    $profile = Profile::create([
        'account_id' => $account->id,
        'username' => 'budget_user',
        'likes' => 120000,
        'revision' => 10,
    ]);

    Http::fake(['*' => Http::response('', 500)]);

    $job = (new RefreshProfile($profile->id))->withFakeQueueInteractions();
    $job->handle(app(FakeUpstreamClient::class));

    $job->assertFailed();
    $job->assertNotReleased();
    expect($profile->refresh()->last_attempt_outcome)->toBe('server_error');
    expect($profile->likes)->toBe(120000);
});

it('honours Retry-After when the upstream sends one', function () {
    $account = Account::create([
        'name' => 'Test Account',
        'credentials' => ['token' => 'test-token'],
        'max_concurrency' => 2,
    ]);

    $profile = Profile::create([
        'account_id' => $account->id,
        'username' => 'retry_after_user',
        'likes' => 120000,
        'revision' => 10,
    ]);

    Http::fake(['*' => Http::response(['error' => 'Too Many Requests'], 429, ['Retry-After' => '45'])]);

    $job = (new RefreshProfile($profile->id))->withFakeQueueInteractions();
    $job->handle(app(FakeUpstreamClient::class));

    $job->assertReleased(45);
});

it('offers the crash hook only after a successful write', function (array $body, int $expectedCalls) {
    $account = Account::create([
        'name' => 'Test Account',
        'credentials' => ['token' => 'test-token'],
        'max_concurrency' => 2,
    ]);

    $profile = Profile::create([
        'account_id' => $account->id,
        'username' => 'crash_hook_user',
        'likes' => 120000,
        'revision' => 10,
    ]);

    Http::fake(['*' => Http::response($body, 200)]);

    $this->mock(CrashInjector::class)
        ->shouldReceive('crashIfFlagged')
        ->times($expectedCalls);

    (new RefreshProfile($profile->id))->handle(app(FakeUpstreamClient::class));
})->with([
    'newer revision is written' => [['profile' => ['likes' => 121000], 'revision' => 11], 1],
    'duplicate revision is stale' => [['likes' => 120000, 'revision' => 10], 0],
]);
