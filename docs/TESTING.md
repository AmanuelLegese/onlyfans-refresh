# Testing Guide

## Running Tests

### Run All Tests

```bash
make test
```

Or directly via Docker:

```bash
docker compose run --rm artisan test
```

### Run a Specific Test File

```bash
docker compose run --rm artisan test tests/Unit/ProfilePayloadTest.php
```

### Run a Specific Test by Name

```bash
docker compose run --rm artisan test --filter="parses old format response"
```

### Run All Unit Tests

```bash
docker compose run --rm artisan test --testsuite=Unit
```

### Run All Feature Tests

```bash
docker compose run --rm artisan test --testsuite=Feature
```

### Run Tests with Verbose Output

```bash
docker compose run --rm artisan test --verbose
```

### Run Tests in Parallel

```bash
docker compose run --rm artisan test --parallel
```

---

## Test Structure

Tests use **Pest 5** with Laravel test bindings. The test suite uses **SQLite in-memory** for fast, isolated database testing.

### Directory Layout

```
src/tests/
├── Pest.php                      # Base test configuration
├── Unit/                         # Isolated unit tests (no HTTP, no DB)
│   ├── ExampleTest.php
│   ├── ProfilePayloadTest.php
│   ├── RefreshPolicyTest.php
│   └── RedactSecretsProcessorTest.php
└── Feature/                      # Integration tests (HTTP, DB, queue)
    ├── ExampleTest.php
    ├── RefreshProfileJobTest.php
    ├── ScheduleRefreshesTest.php
    ├── AccountIsolationTest.php
    ├── DuplicateExecutionTest.php
    ├── OutOfOrderRevisionTest.php
    ├── ModelTest.php
    ├── WorkloadTest.php
    ├── RealOnlyFansClientTest.php
    ├── ProfileControllerTest.php
    └── Legacy/
        └── LegacyBehaviourTest.php
```

### Unit vs Feature Tests

| Type | Database | HTTP | Queue | Use Case |
|------|----------|------|-------|----------|
| **Unit** | No | No | No | Pure logic: parsers, policies, processors |
| **Feature** | SQLite in-memory | Http::fake() | Queue::fake() | Jobs, commands, controllers, models |

---

## Test Files

### Unit Tests (22 tests)

#### `Unit/ExampleTest.php` (1 test)

Basic sanity check that `true` is `true`. Confirms the test framework works.

#### `Unit/ProfilePayloadTest.php` (13 tests)

Tests the `ProfilePayload::fromJson()` dual-format parser:

| Test | Description |
|------|-------------|
| `parses old format response` | Top-level `likes` field reads correctly |
| `parses new format response with profile wrapper` | Nested `profile.likes` reads correctly |
| `prefers profile.likes over top-level likes` | When both exist, `profile.likes` wins |
| `accepts explicit zero likes as valid` | `likes = 0` is accepted |
| `rejects missing likes` | Throws `MalformedResponse` when likes field absent |
| `rejects null likes` | Throws `MalformedResponse` when likes is null |
| `rejects negative likes` | Throws `MalformedResponse` for negative values |
| `rejects string likes` | Throws `MalformedResponse` for non-integer types |
| `rejects string-encoded number likes` | Throws `MalformedResponse` for `"121000"` string |
| `rejects float likes` | Throws `MalformedResponse` for `1.5` |
| `rejects missing revision` | Throws `MalformedResponse` when revision field absent |
| `rejects revision < 1` | Throws `MalformedResponse` for revision 0 |
| `rejects string revision` | Throws `MalformedResponse` for non-integer revision |

#### `Unit/RefreshPolicyTest.php` (4 tests)

Tests the refresh interval policy:

| Test | Description |
|------|-------------|
| `uses 24h interval for profiles above 100_000 likes` | High-likes profiles refresh every 24h |
| `uses 72h interval for profiles at or below 100_000 likes` | Low-likes profiles refresh every 72h |
| `exactly 100_000 uses the 72h interval` | Boundary: 100k uses low interval |
| `100_001 uses the 24h interval` | Boundary: 100,001 uses high interval |

#### `Unit/RedactSecretsProcessorTest.php` (4 tests)

Tests the log processor that redacts sensitive data:

| Test | Description |
|------|-------------|
| `redacts sensitive keys in context` | `token`, `password`, `authorization` become `***REDACTED***` |
| `redacts nested sensitive keys` | Works inside nested arrays |
| `redacts sensitive keys in extra` | Redacts in the `extra` field, not just `context` |
| `is case-insensitive for sensitive keys` | `TOKEN`, `Api_Key`, `Client_Secret` all redacted |

---

### Feature Tests (43 tests)

#### `Feature/ExampleTest.php` (1 test)

Tests that the application returns a successful HTTP 200 response on `/`.

#### `Feature/RefreshProfileJobTest.php` (8 tests)

Core job behavior tests:

| Test | Description |
|------|-------------|
| `applies new format data and stores correct likes/revision` | Happy path: new format parsed, profile updated, attempt logged |
| `does not overwrite valid data with stale revision` | Revision 10 response rejected when profile already at revision 11 |
| `records rate_limited and keeps data intact` | HTTP 429 recorded, profile data unchanged |
| `records server_error and keeps data intact` | HTTP 500 recorded, profile data unchanged |
| `fails on client_error` | HTTP 404 recorded as permanent failure |
| `fails on malformed response` | Non-JSON response recorded as permanent failure |
| `sets next_refresh_at based on likes count` | High-likes profiles get 24h refresh interval |
| `giveUp clears refresh_queued_at and pushes next_refresh_at` | `ProfileWriter::giveUp()` increments failures, clears queue lock, pushes next refresh |

#### `Feature/ScheduleRefreshesTest.php` (6 tests)

Tests the `profiles:schedule-refreshes` artisan command:

| Test | Description |
|------|-------------|
| `dispatches refresh for due profiles` | Profiles past `next_refresh_at` get dispatched |
| `does not dispatch for profiles not yet due` | Future `next_refresh_at` profiles are skipped |
| `does not dispatch for profiles already queued` | `refresh_queued_at` prevents duplicate dispatch |
| `reclaims stale queued profiles` | Stale queue claims (> 1 hour) are reclaimed |
| `dispatches multiple due profiles` | Multiple due profiles all dispatched |
| `respects refresh_queued_at within 1 hour` | Recently queued profiles (< 1h) are skipped |

#### `Feature/AccountIsolationTest.php` (4 tests)

Tests per-account isolation via Redis:

| Test | Description |
|------|-------------|
| `cooldown on account A does not affect account B` | Rate-limit cooldown is scoped per account |
| `concurrency limit releases extra jobs` | Jobs released when account concurrency is at max |
| `account B continues when account A is rate limited` | Account B succeeds while A is rate-limited |
| `concurrent jobs for different accounts run independently` | Both accounts refresh simultaneously |

#### `Feature/DuplicateExecutionTest.php` (2 tests)

Tests idempotency against duplicate job execution:

| Test | Description |
|------|-------------|
| `same job handled twice produces one success and one stale row` | First execution succeeds, second gets `stale_revision` |
| `createOrFirst race produces one profile row` | Concurrent profile creation yields exactly one row |

#### `Feature/OutOfOrderRevisionTest.php` (2 tests)

Tests revision ordering guarantees:

| Test | Description |
|------|-------------|
| `applies rev 11 then rev 10 → still rev 11 with stale_revision` | Out-of-order delivery preserves newer data |
| `applies rev 11 twice → second is stale` | Duplicate revision rejected as stale |

#### `Feature/ModelTest.php` (3 tests)

Tests Eloquent model behavior:

| Test | Description |
|------|-------------|
| `creates a profile with correct defaults` | New profiles have null likes/revision, 0 failures |
| `belongs to an account` | Profile relationship resolves correctly |
| `encrypts account credentials` | Credentials are encrypted at rest, readable on load |

#### `Feature/Legacy/LegacyBehaviourTest.php` (2 tests)

Tests demonstrating the legacy handler bugs:

| Test | Description |
|------|-------------|
| `legacy handler stores 0 when likes is missing from top level` | `$json['likes'] ?? 0` zeroes out new format likes |
| `legacy handler always marks response as success` | No HTTP status checking, no error recording |

#### `Feature/WorkloadTest.php` (4 tests)

Tests for the workload simulation commands:

| Test | Description |
|------|-------------|
| `workload:run dispatches jobs with Queue::fake` | Creates 2 accounts, 70 profiles, dispatches 80 jobs (incl. duplicates) |
| `workload:run sets Redis scenarios for all profiles` | Each profile gets a Redis scenario config |
| `workload:crash-replay creates profile and dispatches` | Crash replay command sets up test state and dispatches |
| `workload:run dispatches in legacy mode` | Legacy mode flag propagated through dispatched jobs |

#### `Feature/RealOnlyFansClientTest.php` (7 tests)

Tests the production HTTP client with faked responses:

| Test | Description |
|------|-------------|
| `real client throws on connection failure` | Connection exception maps to `UpstreamTimeout` |
| `real client throws on 429` | HTTP 429 maps to `RateLimited` with retry-after |
| `real client throws on 500` | HTTP 500 maps to `ServerError` |
| `real client throws on 404` | HTTP 404 maps to `ClientError` |
| `real client maps successful response` | `favoritedCount` mapped to `likes` |
| `response mapper handles profile wrapper format` | `profile.likes` extracted correctly |
| `response mapper handles favoritedCount format` | `favoritedCount` field mapped to `likes` |

#### `Feature/ProfileControllerTest.php` (4 tests)

Tests for the web dashboard controller:

| Test | Description |
|------|-------------|
| `index page returns 200` | Dashboard loads successfully |
| `index page shows profiles` | Username, name, likes displayed |
| `search filters by username` | `?q=alice` shows only matching profiles |
| `refresh dispatches job` | POST to `/profiles/{id}/refresh` dispatches `RefreshProfile` |

---

## Testing Conventions

### Pest Syntax

Use `it()` for test names (readable descriptions):

```php
it('parses new format likes', function () {
    // ...
});
```

Use `test()` for simpler cases:

```php
test('the application returns a successful response', function () {
    // ...
});
```

### Assertions

Use `expect()` for fluent assertions:

```php
expect($payload->likes)->toBe(121000);
expect($profile->revision)->toBe(11);
expect($profile->last_attempt_outcome)->toBe('success');
expect($nextRefresh->timestamp)->toBeGreaterThan(now()->addHours(23)->timestamp);
```

Use `$this->assert*` for Laravel-specific assertions:

```php
$this->assertDatabaseHas('refresh_attempts', [
    'profile_id' => $profile->id,
    'outcome' => 'success',
]);

$this->assertDatabaseCount('profiles', 1);
```

### Exception Testing

```php
it('rejects missing likes', function () {
    ProfilePayload::fromJson(['revision' => 11]);
})->throws(MalformedResponse::class, 'Missing likes field');
```

---

## Writing a New Test

### Unit Test

```bash
docker compose run --rm artisan make:test --unit MyUnitTest
```

```php
<?php

use App\Refresh\ProfilePayload;

it('parses new format likes', function () {
    $payload = ProfilePayload::fromJson([
        'profile' => ['likes' => 121000],
        'username' => 'testuser',
        'revision' => 1,
    ]);

    expect($payload->likes)->toBe(121000);
});
```

### Feature Test

```bash
docker compose run --rm artisan make:test MyFeatureTest
```

```php
<?php

use App\Jobs\RefreshProfile;
use App\Models\{Account, Profile};
use Illuminate\Support\Facades\Http;

it('applies new format data', function () {
    $account = Account::create([
        'name' => 'Test Account',
        'credentials' => ['token' => 'test-token'],
        'max_concurrency' => 2,
    ]);

    $profile = Profile::create([
        'account_id' => $account->id,
        'username' => 'test_user',
        'likes' => 120000,
        'revision' => 10,
    ]);

    Http::fake([
        '*' => Http::response([
            'username' => 'test_user',
            'likes' => 121000,
            'revision' => 11,
        ], 200),
    ]);

    $job = new RefreshProfile($profile->id);
    $job->handle(app(\App\Upstream\FakeUpstreamClient::class));

    $profile->refresh();

    expect($profile->likes)->toBe(121000);
    expect($profile->revision)->toBe(11);
});
```

---

## Mocking HTTP Responses with `Http::fake()`

### Fake All Requests

```php
Http::fake([
    '*' => Http::response(['likes' => 121000], 200),
]);
```

### Fake by URL Pattern

```php
Http::fake([
    '*/user_a' => Http::response(['error' => 'Too Many Requests'], 429),
    '*/user_b' => Http::response(['likes' => 51000], 200),
]);
```

### Simulate Connection Failure

```php
Http::fake(function () {
    throw new \Illuminate\Http\Client\ConnectionException('Connection refused');
});
```

### Simulate Rate Limiting with Retry-After

```php
Http::fake([
    '*' => Http::response(
        ['error' => 'rate limited'],
        429,
        ['Retry-After' => '30']
    ),
]);
```

### Assert HTTP Request Was Made

```php
Http::assertSent(function ($request) {
    return $request->url() === 'http://upstream:8081/api2/profiles/testuser';
});
```

---

## Database Testing with `RefreshDatabase`

Feature tests automatically use `RefreshDatabase` via `Pest.php`. Each test gets a fresh, empty SQLite in-memory database.

### `assertDatabaseHas`

```php
$this->assertDatabaseHas('refresh_attempts', [
    'profile_id' => $profile->id,
    'outcome' => 'success',
    'http_status' => 200,
]);
```

### `assertDatabaseMissing`

```php
$this->assertDatabaseMissing('profiles', [
    'username' => 'deleted_user',
]);
```

### `assertDatabaseCount`

```php
$this->assertDatabaseCount('profiles', 2);
```

### Creating Test Data

```php
$account = Account::create([
    'name' => 'Test Account',
    'credentials' => ['token' => 'test-token'],
    'max_concurrency' => 2,
]);

$profile = Profile::create([
    'account_id' => $account->id,
    'username' => 'test_user',
    'likes' => 120000,
    'revision' => 10,
]);
```

---

## Queue Testing with `Queue::fake()`

### Fake the Queue

```php
use Illuminate\Support\Facades\Queue;

Queue::fake();
```

### Assert a Job Was Dispatched

```php
Queue::assertPushed(RefreshProfile::class, 1);
```

### Assert a Job Was Dispatched with Conditions

```php
Queue::assertPushed(RefreshProfile::class, function ($job) {
    return $job->getMode() === 'legacy';
});
```

### Assert No Jobs Were Dispatched

```php
Queue::assertNothingPushed();
```

### Test Job Dispatch from Commands

```php
it('dispatches refresh for due profiles', function () {
    Queue::fake();

    // ... create test data ...

    Artisan::call('profiles:schedule-refreshes');

    Queue::assertPushed(RefreshProfile::class, 1);
});
```

---

## Redis Testing

Redis is available in feature tests for cooldown and concurrency tests.

### Set and Read Redis Keys

```php
use Illuminate\Support\Facades\Redis;

Redis::set("refresh:cooldown:account:{$account->id}", now()->addSeconds(30)->timestamp, 'EX', 60);

$hasCooldown = Redis::exists("refresh:cooldown:account:{$account->id}");
expect((bool) $hasCooldown)->toBeTrue();
```

### Clean Up Redis After Tests

Redis keys with TTLs expire automatically. For test isolation, keys are scoped to test data (e.g., `account.id`).

---

## Configuration

### Test Database

Tests use SQLite in-memory, configured in `phpunit.xml`:

```xml
<env name="DB_CONNECTION" value="sqlite"/>
<env name="DB_DATABASE" value=":memory:"/>
```

### Test Queue

Tests use the `sync` queue driver (jobs execute immediately):

```xml
<env name="QUEUE_CONNECTION" value="sync"/>
```

### Running Tests Outside Docker

```bash
cd src
php artisan test
# or
./vendor/bin/pest
```
