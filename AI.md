# AI.md

## Tools used

- **Claude Code (mimo-v2.5-free)** — first implementation: models, migrations, jobs, middleware, commands, tests, UI, docs and CI.
- **Claude Code (Claude Opus 5)** — the implementation plan, Docker review and fixes, two reviews against the task brief, and the fixes that followed:
  - the Horizon queue, fake upstream route and legacy/fixed mode switch;
  - the reproduction and workload metrics;
  - the real OnlyFans client;
  - crash replay, profile lock, atomic concurrency limit, retry budget, scheduling and logging;
  - rewriting the tests that didn't test real code;
  - removing the generated docs.
- **Laravel Boost** — Laravel 13 guidelines and its skills for Horizon (supervisor settings, timeout order) and Scout (database engine behaviour).
- **Web search** — to confirm OnlyFans' current request-signing scheme and find a public rules source.

## What was checked by hand

All inside the project containers:

- `docker compose config --quiet` and `docker compose build` pass.
- The full suite passes on SQLite and on Postgres: 101 tests ([docs/evidence/02-tests.txt](docs/evidence/02-tests.txt)).
- A signed, logged-out request to `https://onlyfans.com/api2/v2/users/madison420ivy` returned 200 with the public profile. The same refresh through Horizon stored id 5140520, 606,525 likes and the post/photo/video counts ([05](docs/evidence/05-live-onlyfans.txt)).
- `incident:reproduce` shows the legacy handler storing `likes = 0` and marking a 429 and an empty 500 as success, and the fixed handler keeping the last valid data ([01](docs/evidence/01-reproduction.txt)).
- Legacy and fixed workloads ran through Horizon with 4 workers, and the stored data was checked ([03](docs/evidence/03-workload-legacy.txt), [04](docs/evidence/04-workload-fixed.txt)). Account A's failure timing was checked against the `refresh_attempts` rows.
- The crash replay killed a real Horizon worker process after its write. The same job was redelivered after `retry_after`, and the refresh log shows the whole trail ([06](docs/evidence/06-crash-replay.txt)).
- The refresh log contains no account tokens or cookies; `/` and `/horizon` return 200.
- GitHub Actions passed for `350a558`: the `test` job (Postgres service) and the `docker` job (Compose build, including `build.entitlements`), 101 tests each.

## Incorrect AI suggestions caught

From the first implementation:

1. **`Backoff.php` syntax error** — `$ exponential` (a space after `$`). Fixed manually.
2. **`pushContext()` in Monolog 4** — the method no longer exists. Replaced with `Log::info()`.
3. **`percentile()` on a Collection** — not a Laravel Collection method. Replaced with a manual calculation.
4. **`ilike` in the controller** — changed to `like` for SQLite, which made search case-sensitive on Postgres. Search now goes through Scout's database engine, which uses `ilike` on Postgres.
5. **`Redis::keys()` cleanup** — first treated as a flaky test and the test was removed. The real cause was Laravel's Redis prefix: `keys()` returns prefixed names and `del()` adds the prefix again. Fixed in `WorkloadRun`.
6. **`shouldReceive` on a non-mock** — `RefreshProfile` isn't a Mockery mock. Test restructured.

Found in the reviews against the task brief, all fixed:

7. **Docs described code that didn't exist** — a lock and a crash replay. Both are now implemented; the generated docs were removed.
8. **The fake upstream route lived in `routes/api.php`**, so it was served under `/api/…` and the clients got 404.
9. **Horizon had no published config**, so it never processed the `refresh` queue.
10. **`workload:run --mode=legacy` ran the fixed job.**
11. **The reproduction stacked `Http::fake()` stubs**, so it showed no bug.
12. **Invalid JSON wasn't recorded** — `ProfilePayload::fromJson()` ran outside the job's `try`.
13. **`ProfileWriter::createOrFirst()`** used `lockForUpdate()` outside a transaction, which did nothing. It now uses Eloquent's `createOrFirst()`.
14. **The real client couldn't work:**
    - `RequestSigner` was an invented HMAC, not OnlyFans' algorithm;
    - `ResponseMapper` turned a missing likes value into 0 and used snake_case field names;
    - the client wasn't wired into the job.
15. **Tests that never called the code they named:**
    - the 100,000-likes boundary tests repeated the comparison inline;
    - two legacy tests never ran the handler;
    - the "createOrFirst race" test never called `createOrFirst`.
16. **The per-account concurrency limit read, then incremented** (not atomic); replaced with `Redis::funnel`.
17. **Other gaps:**
    - the retry budget was counted but never enforced;
    - `queue_attempt` was always 1;
    - `giveUp()` counted a failure twice;
    - a success didn't clear the pending claim;
    - never-refreshed profiles were never scheduled;
    - the UI refresh bypassed the claim.

Wrong suggestions from Claude Opus 5, caught during the work:

18. **The fake upstream ran with `artisan serve`** and a container env var. `artisan serve` passes only an allowlist of env vars, so `FAKE_UPSTREAM_ENABLED` was dropped. Replaced with `php -S`.
19. **Docker build DNS workarounds** — for Alpine builds failing to resolve packagist:
    - building on the host network hung on the host's broken IPv6;
    - setting DNS in `/etc/docker/daemon.json` was then suggested — a system-wide change for a project problem, and not applied.

    The fix that stayed: only the Composer step uses `RUN --network=host`.
20. **`laravel new .`** — suggested for installing into `./src`; the installer refuses the current directory.
21. **Horizon `balance: false` with only `maxProcesses: 4`** — started 1 worker. Horizon starts `floor((min + max) / 2)`, so `minProcesses` is set too.
22. **The queue-wide "oldest waiting job" metric** looked worse after the fix, because released jobs keep their dispatch time. Per-account wait and success timing were added.
23. **The pinned signature value in the signer test** was first written as an invented hash. It was replaced with the value computed in the container before the test was run.
24. **A Pest dataset built `Http::response()` objects** at file load, before the app booted. Rewritten to pass plain values.
25. **A `make live` default used `$(USERNAME)`,** which the shell environment already sets. Renamed to `OF_USER`.

## What was NOT verified

- **Logged-in OnlyFans requests** (cookie, `user_id`), request volume against real rate limits, and how long the community signing rules stay valid. Live retrieval was checked with single requests on 15 September 2026.
- **Loss of a container or host, Redis failover losing reserved jobs, or a lost database commit.** The crash replay kills a worker process only.
- **Production deployment, rollout and rollback.**
- **Repeatability of the workload numbers:** each mode was measured once for the README table.

## Running tests

```bash
make test
# or
docker compose run --rm artisan test
```

## Test structure

- `tests/Unit/` — ProfilePayloadTest (13), RefreshPolicyTest (9), RedactSecretsProcessorTest (4), ExampleTest (1)
- `tests/Feature/` — RefreshProfileJobTest (19), RealOnlyFansClientTest (12), ScheduleRefreshesTest (8), LegacyBehaviourTest (6), ProfileControllerTest (6), WorkloadTest (5), AccountIsolationTest (4), FakeUpstreamControllerTest (4), ModelTest (3), CrashInjectorTest (2), DuplicateExecutionTest (2), OutOfOrderRevisionTest (2), ExampleTest (1)
