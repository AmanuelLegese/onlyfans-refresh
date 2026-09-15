<?php

use App\Jobs\RefreshProfile;
use App\Models\Account;
use App\Models\Profile;
use App\Refresh\Exceptions\ClientError;
use App\Refresh\Exceptions\RateLimited;
use App\Refresh\Exceptions\ServerError;
use App\Refresh\Exceptions\SignatureRejected;
use App\Refresh\Exceptions\UpstreamTimeout;
use App\Upstream\ProfileSource;
use App\Upstream\RealOnlyFansClient;
use App\Upstream\RequestSigner;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

const TEST_RULES = [
    'static_param' => 'abcdef0123456789abcdef0123456789',
    'format' => '12345:{}:{:x}:6789abcd',
    'checksum_indexes' => [0, 5, 10, 15, 20, 25, 30, 35],
    'checksum_constant' => 42,
    'app_token' => 'test-app-token',
];

function fakeOnlyFans(mixed $profileResponse): void
{
    Http::fake([
        'raw.githubusercontent.com/*' => Http::response(TEST_RULES),
        'onlyfans.com/*' => $profileResponse,
    ]);
}

function liveAccount(array $credentials = []): Account
{
    return Account::create([
        'name' => 'OnlyFans (public, logged out)',
        'source' => Account::SOURCE_ONLYFANS,
        'credentials' => $credentials,
        'max_concurrency' => 1,
    ]);
}

function onlyFansFixture(): array
{
    return json_decode(file_get_contents(base_path('tests/Fixtures/onlyfans/madison420ivy.json')), true);
}

it('signs with sha1 of static_param, time, path and user id plus the checksum format', function () {
    $sign = RequestSigner::sign(TEST_RULES, '/api2/v2/users/madison420ivy', '1789000000000', '0');

    // sha1("abcdef…789\n1789000000000\n/api2/v2/users/madison420ivy\n0"); checksum 0x25d.
    expect($sign)->toBe('12345:4ae32643e5a803973f48d988942f93f05f2338f0:25d:6789abcd');
});

it('sends the signed headers for a logged-out request and maps the profile', function () {
    fakeOnlyFans(Http::response(onlyFansFixture()));

    $result = app(RealOnlyFansClient::class)->fetch(liveAccount(), 'madison420ivy');

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://onlyfans.com/api2/v2/users/madison420ivy'
            && $request->hasHeader('app-token', 'test-app-token')
            && $request->hasHeader('user-id', '0')
            && preg_match('/^12345:[0-9a-f]{40}:[0-9a-f]+:6789abcd$/', $request->header('sign')[0]) === 1
            && ctype_digit($request->header('time')[0])
            && strlen($request->header('x-bc')[0]) === 40
            && ! $request->hasHeader('cookie');
    });

    expect($result['likes'])->toBe(606525);
    expect($result['upstream_id'])->toBe(5140520);
    expect($result['name'])->toBe('Madison Ivy');
    expect($result['posts_count'])->toBe(174);
    expect($result['photos_count'])->toBe(573);
    expect($result['videos_count'])->toBe(216);
    expect($result['revision'])->toBeGreaterThan(1_700_000_000_000);
    expect($result['profile_data'])->toHaveKeys(['id', 'username', 'joinDate', 'isVerified'])
        ->not->toHaveKeys(['favoritedCount', 'subscribedBy', 'canEarn', '_note']);
});

it('maps upstream failures to typed exceptions', function (int $status, array $headers, string $exception) {
    fakeOnlyFans(Http::response(['error' => 'upstream'], $status, $headers));

    expect(fn () => app(RealOnlyFansClient::class)->fetch(liveAccount(), 'madison420ivy'))->toThrow($exception);
})->with([
    '429' => [429, ['Retry-After' => '30'], RateLimited::class],
    '500' => [500, [], ServerError::class],
    '404' => [404, [], ClientError::class],
    '403' => [403, [], SignatureRejected::class],
]);

it('throws a timeout when the connection fails', function () {
    Http::fake([
        'raw.githubusercontent.com/*' => Http::response(TEST_RULES),
        'onlyfans.com/*' => fn () => throw new ConnectionException('Connection refused'),
    ]);

    expect(fn () => app(RealOnlyFansClient::class)->fetch(liveAccount(), 'madison420ivy'))->toThrow(UpstreamTimeout::class);
});

it('drops cached signing rules when a signature is rejected', function () {
    fakeOnlyFans(Http::response(['error' => 'bad sign'], 401));
    $client = app(RealOnlyFansClient::class);

    expect(fn () => $client->fetch(liveAccount(), 'madison420ivy'))->toThrow(SignatureRejected::class);
    expect(Cache::has('onlyfans:dynamic_rules'))->toBeFalse();
});

it('treats invalid signing rules as a retryable server error', function () {
    Http::fake(['raw.githubusercontent.com/*' => Http::response(['static_param' => 'x'])]);

    expect(fn () => app(RealOnlyFansClient::class)->fetch(liveAccount(), 'madison420ivy'))->toThrow(ServerError::class);
});

it('records a response without favoritedCount as malformed instead of zero likes', function () {
    $body = onlyFansFixture();
    unset($body['favoritedCount']);
    fakeOnlyFans(Http::response($body));

    $account = liveAccount();
    $profile = Profile::create(['account_id' => $account->id, 'username' => 'madison420ivy', 'likes' => 606000, 'revision' => 1]);

    (new RefreshProfile($profile->id))->withFakeQueueInteractions()->handle(app(ProfileSource::class));

    $profile->refresh();
    expect($profile->likes)->toBe(606000);
    expect($profile->last_attempt_outcome)->toBe('malformed');
});

it('refreshes a live account end to end through the source router', function () {
    fakeOnlyFans(Http::response(onlyFansFixture()));

    $account = liveAccount();
    $profile = Profile::create(['account_id' => $account->id, 'username' => 'madison420ivy']);

    (new RefreshProfile($profile->id))->handle(app(ProfileSource::class));

    $profile->refresh();
    expect($profile->last_attempt_outcome)->toBe('success');
    expect($profile->likes)->toBe(606525);
    expect($profile->upstream_id)->toBe(5140520);
    expect($profile->name)->toBe('Madison Ivy');
    expect($profile->profile_data['joinDate'])->toBe('2018-11-11T00:00:00+00:00');
    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://onlyfans.com/'));
});

it('routes fake accounts to the fake upstream', function () {
    Http::fake(['upstream:8081/*' => Http::response(['profile' => ['likes' => 1], 'revision' => 2])]);

    $account = Account::create(['name' => 'Fake', 'credentials' => ['token' => 't'], 'max_concurrency' => 1]);

    expect($account->source)->toBe(Account::SOURCE_FAKE);
    expect(app(ProfileSource::class)->fetch($account, 'someone')['revision'])->toBe(2);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'upstream:8081/fake/api/users/someone'));
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'onlyfans.com'));
});
