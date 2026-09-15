<?php

use App\Refresh\CrashInjector;
use Illuminate\Support\Facades\Redis;

it('does nothing and keeps the flag when crash injection is disabled', function () {
    config(['refresh.crash_injection' => false]);
    $injector = new CrashInjector;

    $injector->flag(987654);
    $injector->crashIfFlagged(987654, 'job-uuid');

    // Still running, and the one-shot flag was not consumed.
    expect(Redis::get('refresh:crash_after_write:987654'))->toBe('1');
    Redis::del('refresh:crash_after_write:987654');
});

it('does nothing when enabled but the profile has no flag', function () {
    config(['refresh.crash_injection' => true]);
    Redis::del('refresh:crash_after_write:987655');

    (new CrashInjector)->crashIfFlagged(987655, 'job-uuid');

    expect(Redis::get('refresh:crash_after_write:987655'))->toBeNull();
});
