<?php

use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    config(['refresh.upstream_enabled' => true]);
});

function setFakeScenario(string $username, array $scenario): void
{
    Redis::set("fake:scenario:{$username}", json_encode($scenario + ['seed' => 'test', 'latency_ms' => 0]));
    Redis::del("fake:request_count:{$username}");
}

it('serves the fake profile at the path the clients call, without an /api prefix', function () {
    setFakeScenario('fake_route_user', ['format' => 'new', 'revision' => 11, 'likes' => 121000]);

    $this->getJson('/fake/api/users/fake_route_user')
        ->assertOk()
        ->assertJsonPath('profile.likes', 121000)
        ->assertJsonPath('revision', 11);

    $this->getJson('/api/fake/api/users/fake_route_user')->assertNotFound();
});

it('returns 404 when the fake upstream is disabled', function () {
    config(['refresh.upstream_enabled' => false]);

    $this->getJson('/fake/api/users/anyone')->assertNotFound();
});

it('returns 429 without a Retry-After header', function () {
    setFakeScenario('fake_429_user', ['p429' => 100]);

    $response = $this->get('/fake/api/users/fake_429_user');

    $response->assertStatus(429);
    expect($response->headers->has('Retry-After'))->toBeFalse();
});

it('returns 500 with an empty body', function () {
    setFakeScenario('fake_500_user', ['p500_empty' => 100]);

    $response = $this->get('/fake/api/users/fake_500_user');

    $response->assertStatus(500);
    expect($response->getContent())->toBe('');
});
