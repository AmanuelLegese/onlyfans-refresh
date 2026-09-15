# AI.md

## Tools used

- **Claude Code (mimo-v2.5-free)** — first implementation: models, migrations, jobs, middleware, commands, tests, UI, docs and CI.
- **Claude Code (Claude Opus 5)** — the plan (`PLAN.md`), Docker review and fixes, a review of the implementation against `job.md`, and the fixes in commit `586fdbe`: Horizon queue config, fake upstream route, legacy/fixed mode switch, incident reproduction, workload metrics and this README.
- **Laravel Boost** — Laravel 13 guidelines and its Horizon skill, used to check the supervisor settings (`balance: false`, timeout order).

## What was checked by hand

All inside the project containers:

- `docker compose config --quiet` and `docker compose build` pass.
- The full suite passes: 80 tests ([docs/evidence/10-final-tests.txt](docs/evidence/10-final-tests.txt)).
- `incident:reproduce` shows the legacy handler storing `likes = 0` and marking a 429 and an empty 500 as success, and the fixed handler keeping the last valid data ([01-reproduction.txt](docs/evidence/01-reproduction.txt)).
- Horizon runs 4 workers on the `refresh` queue (`horizon:supervisors`), and the fake upstream answers at `http://upstream:8081/fake/api/users/{username}` from the Horizon container.
- Legacy and fixed workloads ran through Horizon, and the stored data was checked (`madison420ivy`, correct and zeroed profiles, duplicate rows) ([11](docs/evidence/11-workload-legacy.txt), [12](docs/evidence/12-workload-fixed.txt)).
- `/` and `/horizon` return 200 through nginx.

## Incorrect AI suggestions caught

From the first implementation:

1. **`Backoff.php` syntax error** — `$ exponential` (a space after `$`). Fixed manually.
2. **`pushContext()` in Monolog 4** — the method no longer exists. Replaced with `Log::info()`.
3. **`percentile()` on a Collection** — not a Laravel Collection method. Replaced with a manual calculation.
4. **`ilike` in the controller** — Postgres-only, and the tests run on SQLite, so it was changed to `like`. Side effect: search is now case-sensitive on Postgres.
5. **`Redis::keys()` cleanup** — first treated as a flaky test and the test was removed. The real cause was Laravel's Redis prefix: `keys()` returns prefixed names and `del()` adds the prefix again, so nothing was deleted. Fixed in `WorkloadRun`.
6. **`shouldReceive` on a non-mock** — `RefreshProfile` isn't a Mockery mock. Test restructured.

Found in the review against `job.md`, and fixed:

7. **Docs described code that didn't exist** — a `WithoutOverlapping` lock with a 120s expiry, and a crash replay that kills the worker. Removed from the README; both are listed as not done.
8. **The fake upstream route lived in `routes/api.php`** — so it was served under `/api/…`, and the clients got 404. Tests passed only because they fake HTTP.
9. **Horizon had no published config** — so it processed only the `default` queue while every workload job waited on `refresh`. The saved workload reports showed 0 attempts.
10. **`workload:run --mode=legacy` ran the fixed job**, just labelled "legacy".
11. **The reproduction didn't reproduce the bug** — it called `Http::fake()` once per scenario, the stubs stacked, and every scenario got the first response.
12. **Invalid JSON wasn't recorded** — `ProfilePayload::fromJson()` ran outside the job's `try`, so it escaped as an unhandled exception with no failure recorded.

Found in the review, still open (listed in the README):

13. **`ProfileWriter::createOrFirst()`** uses `lockForUpdate()` outside a transaction, which has no effect.
14. **`ResponseMapper`** defaults a missing likes value to 0, and **`RequestSigner`** is not OnlyFans' signing algorithm.

Wrong suggestions from Claude Opus 5, caught during the work:

15. **The fake upstream ran with `artisan serve`** and a container env var. `artisan serve` passes only an allowlist of env vars to the PHP server, so `FAKE_UPSTREAM_ENABLED` was silently dropped. Replaced with `php -S`.
16. **Docker build DNS workarounds** — for Alpine builds failing to resolve packagist:
    - building on the host network hung on the host's broken IPv6, and was reverted;
    - setting DNS in `/etc/docker/daemon.json` was then suggested — a system-wide change for a project problem, and not applied.

    The fix that stayed: only the Composer step uses `RUN --network=host`.
17. **`laravel new .`** — suggested for installing into `./src`; the installer refuses the current directory.
18. **Horizon `balance: false` with only `maxProcesses: 4`** — started 1 worker instead of 4. Horizon starts `floor((min + max) / 2)` workers, so `minProcesses` is now set too.
19. **The queue-wide "oldest waiting job" metric** looked worse after the fix (44.8s), because released jobs keep their original dispatch time. Per-account wait and success timing were added rather than reporting that number alone.

## What was NOT verified

- Live OnlyFans requests: there are no credentials, and the request signer is a placeholder.
- A real worker crash and replay (crash replay is not implemented), Redis failover, or container/host loss.
- Production deployment, rollout and rollback.
- Postgres and Horizon behaviour in the test suite: tests run on SQLite with the sync queue. The workload covers that path, measured once per mode.
- `docs/API.md`, `ARCHITECTURE.md`, `DEPLOYMENT.md`, `DEVELOPMENT.md`, `TESTING.md` and `TROUBLESHOOTING.md` were not re-checked against the code after these fixes.

## Running tests

```bash
make test
# or
docker compose run --rm artisan test
```

## Test structure

- `tests/Unit/` — ProfilePayloadTest (13), RefreshPolicyTest (4), RedactSecretsProcessorTest (4), ExampleTest (1)
- `tests/Feature/` — RefreshProfileJobTest (14), RealOnlyFansClientTest (7), ScheduleRefreshesTest (6), LegacyBehaviourTest (6), WorkloadTest (5), AccountIsolationTest (4), FakeUpstreamControllerTest (4), ProfileControllerTest (4), ModelTest (3), DuplicateExecutionTest (2), OutOfOrderRevisionTest (2), ExampleTest (1)
