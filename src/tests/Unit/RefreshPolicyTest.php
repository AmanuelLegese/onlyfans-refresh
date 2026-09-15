<?php

use Illuminate\Support\Facades\Config;

it('uses 24h interval for profiles above 100_000 likes', function () {
    $intervalHigh = Config::get('refresh.interval_high_likes');
    expect($intervalHigh)->toBe(24 * 3600);
});

it('uses 72h interval for profiles at or below 100_000 likes', function () {
    $intervalLow = Config::get('refresh.interval_low_likes');
    expect($intervalLow)->toBe(72 * 3600);
});

it('exactly 100_000 uses the 72h interval', function () {
    $likes = 100_000;
    $interval = $likes > 100_000
        ? Config::get('refresh.interval_high_likes')
        : Config::get('refresh.interval_low_likes');

    expect($interval)->toBe(72 * 3600);
});

it('100_001 uses the 24h interval', function () {
    $likes = 100_001;
    $interval = $likes > 100_000
        ? Config::get('refresh.interval_high_likes')
        : Config::get('refresh.interval_low_likes');

    expect($interval)->toBe(24 * 3600);
});
