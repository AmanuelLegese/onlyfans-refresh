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
    // Community-maintained signing rules (static_param, checksum, format, app_token). OnlyFans
    // rotates them, so they are cached briefly and dropped when a request is rejected with 401/403.
    'onlyfans_rules_url' => env('ONLYFANS_RULES_URL', 'https://raw.githubusercontent.com/DATAHOARDERS/dynamic-rules/main/onlyfans.json'),
    'onlyfans_rules_ttl' => (int) env('ONLYFANS_RULES_TTL', 300),
    'onlyfans_user_agent' => env('ONLYFANS_USER_AGENT', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36'),

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
    | Locks (seconds) — both must be longer than job_timeout
    |--------------------------------------------------------------------------
    |
    | profile_lock: WithoutOverlapping lock per profile; only expires on its own if a worker died.
    | concurrency_slot: per-account Redis::funnel slot; frees the slot of a killed worker.
    */
    'profile_lock_seconds' => (int) env('REFRESH_PROFILE_LOCK_SECONDS', 120),
    'concurrency_slot_seconds' => (int) env('REFRESH_CONCURRENCY_SLOT_SECONDS', 60),

    /*
    |--------------------------------------------------------------------------
    | Crash injection (local only)
    |--------------------------------------------------------------------------
    |
    | When true, a job whose profile has a one-shot crash flag kills its own worker with SIGKILL
    | right after the database write. Used by workload:crash-replay; keep false in production.
    */
    'crash_injection' => (bool) env('REFRESH_CRASH_INJECTION', false),

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
