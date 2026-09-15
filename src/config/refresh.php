<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Upstream Configuration
    |--------------------------------------------------------------------------
    */
    'upstream_url' => env('FAKE_UPSTREAM_URL', 'http://upstream:8081'),
    'upstream_enabled' => env('FAKE_UPSTREAM_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Real OnlyFans Configuration
    |--------------------------------------------------------------------------
    */
    'onlyfans_api_url' => env('ONLYFANS_API_URL', 'https://onlyfans.com'),
    'onlyfans_rules_url' => env('ONLYFANS_RULES_URL'),

    /*
    |--------------------------------------------------------------------------
    | Refresh Mode
    |--------------------------------------------------------------------------
    */
    // `fixed` in normal use. `legacy` runs the original broken handler and exists only to
    // reproduce the incident; it is not a rollback target.
    'mode' => env('REFRESH_MODE', 'fixed'),

    /*
    |--------------------------------------------------------------------------
    | HTTP Timeouts (seconds)
    |--------------------------------------------------------------------------
    */
    'http_timeout' => (int) env('REFRESH_HTTP_TIMEOUT', 10),
    'http_connect_timeout' => (int) env('REFRESH_HTTP_CONNECT_TIMEOUT', 3),

    /*
    |--------------------------------------------------------------------------
    | Job Timeout (seconds) — must be < Redis retry_after
    |--------------------------------------------------------------------------
    */
    'job_timeout' => (int) env('REFRESH_JOB_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Retry Budget
    |--------------------------------------------------------------------------
    */
    'max_upstream_attempts' => (int) env('REFRESH_MAX_UPSTREAM_ATTEMPTS', 6),

    /*
    |--------------------------------------------------------------------------
    | Backoff
    |--------------------------------------------------------------------------
    */
    'backoff_base' => (int) env('REFRESH_BACKOFF_BASE', 1),
    'backoff_cap' => (int) env('REFRESH_BACKOFF_CAP', 8),

    /*
    |--------------------------------------------------------------------------
    | Refresh Intervals (seconds)
    |--------------------------------------------------------------------------
    */
    'interval_high_likes' => 24 * 3600,   // > 100_000 likes
    'interval_low_likes' => 72 * 3600,    // <= 100_000 likes

    /*
    |--------------------------------------------------------------------------
    | Give-up Backoff
    |--------------------------------------------------------------------------
    */
    'giveup_base_minutes' => 5,
    'giveup_cap_hours' => 6,
];
