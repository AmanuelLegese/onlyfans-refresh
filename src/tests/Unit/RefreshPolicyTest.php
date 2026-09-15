<?php

use App\Refresh\RefreshPolicy;

it('uses 24h interval for profiles above 100_000 likes', function (int $likes) {
    expect(RefreshPolicy::intervalFor($likes))->toBe(24 * 3600);
})->with([100_001, 121_000, 606_525]);

it('uses 72h interval for profiles at or below 100_000 likes', function (int $likes) {
    expect(RefreshPolicy::intervalFor($likes))->toBe(72 * 3600);
})->with([0, 1, 99_999, 100_000]);

it('exactly 100_000 uses the 72h interval', function () {
    $this->travelTo(now()->startOfSecond());

    expect(RefreshPolicy::intervalFor(100_000))->toBe(72 * 3600);
    expect(RefreshPolicy::nextRefreshAt(100_000)->equalTo(now()->addHours(72)))->toBeTrue();
});

it('100_001 uses the 24h interval', function () {
    $this->travelTo(now()->startOfSecond());

    expect(RefreshPolicy::intervalFor(100_001))->toBe(24 * 3600);
    expect(RefreshPolicy::nextRefreshAt(100_001)->equalTo(now()->addHours(24)))->toBeTrue();
});
