<?php

use App\Refresh\ProfilePayload;
use App\Refresh\Exceptions\MalformedResponse;

it('parses old format response', function () {
    $json = [
        'username' => 'test_user',
        'likes' => 120000,
        'revision' => 10,
        'name' => 'Test User',
        'avatar_url' => 'https://example.com/avatar.jpg',
        'posts_count' => 100,
        'photos_count' => 50,
        'videos_count' => 10,
    ];

    $payload = ProfilePayload::fromJson($json);

    expect($payload->likes)->toBe(120000);
    expect($payload->revision)->toBe(10);
    expect($payload->name)->toBe('Test User');
    expect($payload->avatarUrl)->toBe('https://example.com/avatar.jpg');
    expect($payload->postsCount)->toBe(100);
    expect($payload->photosCount)->toBe(50);
    expect($payload->videosCount)->toBe(10);
});

it('parses new format response with profile wrapper', function () {
    $json = [
        'username' => 'test_user',
        'profile' => ['likes' => 121000],
        'revision' => 11,
        'name' => 'Test User',
        'avatar_url' => 'https://example.com/avatar.jpg',
        'posts_count' => 100,
        'photos_count' => 50,
        'videos_count' => 10,
    ];

    $payload = ProfilePayload::fromJson($json);

    expect($payload->likes)->toBe(121000);
    expect($payload->revision)->toBe(11);
});

it('prefers profile.likes over top-level likes', function () {
    $json = [
        'likes' => 120000,
        'profile' => ['likes' => 121000],
        'revision' => 11,
    ];

    $payload = ProfilePayload::fromJson($json);

    expect($payload->likes)->toBe(121000);
});

it('accepts explicit zero likes as valid', function () {
    $json = [
        'likes' => 0,
        'revision' => 10,
    ];

    $payload = ProfilePayload::fromJson($json);

    expect($payload->likes)->toBe(0);
});

it('rejects missing likes', function () {
    $json = [
        'username' => 'test_user',
        'revision' => 11,
    ];

    ProfilePayload::fromJson($json);
})->throws(MalformedResponse::class, 'Missing likes field');

it('rejects null likes', function () {
    $json = [
        'likes' => null,
        'revision' => 11,
    ];

    ProfilePayload::fromJson($json);
})->throws(MalformedResponse::class, 'Missing likes field');

it('rejects negative likes', function () {
    $json = [
        'likes' => -5,
        'revision' => 11,
    ];

    ProfilePayload::fromJson($json);
})->throws(MalformedResponse::class, 'Likes must be non-negative');

it('rejects string likes', function () {
    $json = [
        'likes' => 'abc',
        'revision' => 11,
    ];

    ProfilePayload::fromJson($json);
})->throws(MalformedResponse::class, 'Likes must be an integer');

it('rejects string-encoded number likes', function () {
    $json = [
        'likes' => '121000',
        'revision' => 11,
    ];

    ProfilePayload::fromJson($json);
})->throws(MalformedResponse::class, 'Likes must be an integer');

it('rejects float likes', function () {
    $json = [
        'likes' => 1.5,
        'revision' => 11,
    ];

    ProfilePayload::fromJson($json);
})->throws(MalformedResponse::class, 'Likes must be an integer');

it('rejects missing revision', function () {
    $json = [
        'likes' => 121000,
    ];

    ProfilePayload::fromJson($json);
})->throws(MalformedResponse::class, 'Missing revision field');

it('rejects revision < 1', function () {
    $json = [
        'likes' => 121000,
        'revision' => 0,
    ];

    ProfilePayload::fromJson($json);
})->throws(MalformedResponse::class, 'Revision must be >= 1');

it('rejects string revision', function () {
    $json = [
        'likes' => 121000,
        'revision' => 'abc',
    ];

    ProfilePayload::fromJson($json);
})->throws(MalformedResponse::class, 'Revision must be an integer');
