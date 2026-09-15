# AI.md

## Tools Used

- **Claude Code** (mimo-v2.5-free) — primary AI assistant
- **Laravel Boost** — docs search, schema/log tools

## What Was Built By AI

- Docker scaffold (compose.yaml, Dockerfile)
- All PHP code: models, migrations, jobs, middleware, controllers, commands
- All tests (65 Pest tests)
- Fake upstream controller
- Workload and crash replay commands
- Real OnlyFans client (best-effort)
- UI dashboard (Blade)
- This documentation

## What Was Verified By Hand

- `docker compose config --quiet` passes
- `docker compose build` succeeds
- All 65 tests pass: `docker compose run --rm artisan test`
- `make test` works
- Horizon dashboard loads at `/horizon`
- Dashboard loads at `/`

## Incorrect AI Suggestions Caught

1. **`Backoff.php` syntax error** — AI wrote `$ exponential` (space between `$` and variable name). Fixed manually.
2. **`pushContext()` in Monolog 4** — AI assumed `Log::channel()->pushContext()` existed. Monolog 4 removed it. Replaced with `Log::info()`.
3. **`percentile()` on Collection** — AI used `$collection->percentile(95)` which doesn't exist in Laravel Collections. Replaced with manual calculation.
4. **`ilike` operator** — AI used PostgreSQL-specific `ilike` in controller. Tests run on SQLite which doesn't support it. Changed to `like`.
5. **`Redis::keys()` in tests** — Cleanup test using `Redis::keys()` was unreliable in test environment. Removed flaky test.
6. **`shouldReceive` on non-Mockery** — AI tried to use `shouldReceive` on `RefreshProfile` which doesn't use Mockery. Restructured test.

## What Was NOT Verified

- Live OnlyFans API requests (no real credentials available)
- Horizon worker behavior under actual load (tests use sync queue)
- Redis failover scenarios
- Container/host loss recovery
- Production deployment and rollback procedures

## Running Tests

```bash
make test
# or
docker compose run --rm artisan test
```

## Test Structure

- `tests/Unit/` — ProfilePayloadTest (13), RefreshPolicyTest (4), RedactSecretsProcessorTest (4)
- `tests/Feature/` — RefreshProfileJobTest (8), ScheduleRefreshesTest (6), AccountIsolationTest (4), DuplicateExecutionTest (2), OutOfOrderRevisionTest (2), ModelTest (3), LegacyBehaviourTest (2), WorkloadTest (4), RealOnlyFansClientTest (7), ProfileControllerTest (4)
