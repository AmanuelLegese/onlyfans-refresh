<?php

use App\Models\Account;
use App\Upstream\{RealOnlyFansClient, RequestSigner, ResponseMapper};
use Illuminate\Support\Facades\Http;

it('real client throws on connection failure', function () {
    Http::fake(function () {
        throw new \Illuminate\Http\Client\ConnectionException('Connection refused');
    });

    $account = Account::create([
        'name' => 'Real Test',
        'credentials' => [
            'cookie' => 'sess=test123',
            'x-bc' => 'test-bc',
            'user_agent' => 'TestAgent/1.0',
            'app_token' => 'test-app-token',
        ],
        'max_concurrency' => 1,
    ]);

    $client = new RealOnlyFansClient(new RequestSigner(), new ResponseMapper());

    $this->expectException(\App\Refresh\Exceptions\UpstreamTimeout::class);
    $client->fetch($account, 'testuser');
});

it('real client throws on 429', function () {
    Http::fake([
        '*' => Http::response(['error' => 'rate limited'], 429, ['Retry-After' => '30']),
    ]);

    $account = Account::create([
        'name' => 'Real Test',
        'credentials' => [
            'cookie' => 'sess=test123',
            'x-bc' => 'test-bc',
            'user_agent' => 'TestAgent/1.0',
            'app_token' => 'test-app-token',
        ],
        'max_concurrency' => 1,
    ]);

    $client = new RealOnlyFansClient(new RequestSigner(), new ResponseMapper());

    try {
        $client->fetch($account, 'testuser');
        $this->fail('Expected RateLimited exception');
    } catch (\App\Refresh\Exceptions\RateLimited $e) {
        expect($e->getMessage())->toContain('Rate limited');
        expect($e->getCode())->toBe(429);
    }
});

it('real client throws on 500', function () {
    Http::fake([
        '*' => Http::response([], 500),
    ]);

    $account = Account::create([
        'name' => 'Real Test',
        'credentials' => [
            'cookie' => 'sess=test123',
            'x-bc' => 'test-bc',
            'user_agent' => 'TestAgent/1.0',
            'app_token' => 'test-app-token',
        ],
        'max_concurrency' => 1,
    ]);

    $client = new RealOnlyFansClient(new RequestSigner(), new ResponseMapper());

    $this->expectException(\App\Refresh\Exceptions\ServerError::class);
    $client->fetch($account, 'testuser');
});

it('real client throws on 404', function () {
    Http::fake([
        '*' => Http::response(['error' => 'not found'], 404),
    ]);

    $account = Account::create([
        'name' => 'Real Test',
        'credentials' => [
            'cookie' => 'sess=test123',
            'x-bc' => 'test-bc',
            'user_agent' => 'TestAgent/1.0',
            'app_token' => 'test-app-token',
        ],
        'max_concurrency' => 1,
    ]);

    $client = new RealOnlyFansClient(new RequestSigner(), new ResponseMapper());

    $this->expectException(\App\Refresh\Exceptions\ClientError::class);
    $client->fetch($account, 'testuser');
});

it('real client maps successful response', function () {
    Http::fake([
        '*' => Http::response([
            'name' => 'Test User',
            'avatar' => 'https://example.com/avatar.jpg',
            'photosCount' => 100,
            'videosCount' => 20,
            'postsCount' => 50,
            'favoritedCount' => 150000,
        ], 200),
    ]);

    $account = Account::create([
        'name' => 'Real Test',
        'credentials' => [
            'cookie' => 'sess=test123',
            'x-bc' => 'test-bc',
            'user_agent' => 'TestAgent/1.0',
            'app_token' => 'test-app-token',
        ],
        'max_concurrency' => 1,
    ]);

    $client = new RealOnlyFansClient(new RequestSigner(), new ResponseMapper());
    $result = $client->fetch($account, 'testuser');

    expect($result)->toHaveKey('likes');
    expect($result['likes'])->toBe(150000);
    expect($result['revision'])->toBeInt();
});

it('response mapper handles profile wrapper format', function () {
    $mapper = new ResponseMapper();

    $result = $mapper->map([
        'name' => 'Test User',
        'profile' => ['likes' => 200000],
        'posts_count' => 10,
    ]);

    expect($result['likes'])->toBe(200000);
    expect($result['posts_count'])->toBe(10);
    expect($result['name'])->toBe('Test User');
});

it('response mapper handles favoritedCount format', function () {
    $mapper = new ResponseMapper();

    $result = $mapper->map([
        'favoritedCount' => 75000,
        'name' => 'User',
    ]);

    expect($result['likes'])->toBe(75000);
});
