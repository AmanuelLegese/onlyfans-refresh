# OnlyFans Profile Refresh Service

Laravel 13 service that refreshes OnlyFans profile data through Horizon queue workers, built to reproduce and fix an upstream incident: the API moved `likes` into a `profile{}` object and started returning 429s (without `Retry-After`) and empty 500s.

## The failure

The original handler (`App\Jobs\Legacy\LegacyRefreshProfile`, kept only for reproduction):

- reads `$json['likes'] ?? 0`, so the new format stores `likes = 0`;
- never checks the HTTP status, so a 429 or 500 is parsed as a profile and overwrites the last valid data;
- writes every response as a successful refresh, with no revision check, so an older response arriving last replaces newer data.

Workers therefore report completed jobs while profiles show zeroed or stale data.

## Evidence

All output below was produced inside the project containers.

**Reproduction** ([docs/evidence/01-reproduction.txt](docs/evidence/01-reproduction.txt), `make reproduce`). Each case starts from the last valid state: likes 120000, revision 9.

| Upstream response | Legacy handler stores | Fixed handler stores |
|---|---|---|
| 200 `{likes:120000, revision:10}` | 120000 / rev 10, success | 120000 / rev 10, success |
| 200 `{profile:{likes:121000}, revision:11}` | **0** / rev 11, success | 121000 / rev 11, success |
| 429, no `Retry-After` | **0** / rev **null**, success | 120000 / rev 9 kept, `rate_limited` |
| 500, empty body | **0** / rev **null**, success | 120000 / rev 9 kept, `server_error` |
| rev 11, then rev 10 arrives last | **120000 / rev 10** (overwritten), success | 121000 / rev 11 kept, `stale_revision` |

**Tests** ([docs/evidence/10-final-tests.txt](docs/evidence/10-final-tests.txt)): 80 passing. They include the legacy handler's bugs (`tests/Feature/Legacy`), both formats, invalid `likes` (missing, negative, string, float) and missing revision, 429/500/timeout handling, duplicate execution, an older revision arriving last, and the 24h/72h threshold (exactly 100,000 is 72h). Tests run on SQLite in memory with the sync queue; Postgres, Redis and Horizon behaviour is exercised by the workload runs below, not by the test suite.

## The fix

1. **Validate before writing** — `ProfilePayload::fromJson()` reads `profile.likes` then top-level `likes`. A missing `likes` is invalid, `0` is valid, and negative, string or float values are rejected. Validation runs inside the job's `try`, so an invalid body is recorded as `malformed` and never touches stored data.
2. **Only newer data wins** — `ProfileWriter::applySuccess()` is one conditional `UPDATE … WHERE revision IS NULL OR revision < ?`. Zero rows updated means `stale_revision`: only the attempt fields change. `username` is unique, so duplicate jobs cannot create duplicate profiles.
3. **Failures never look like success** — the client maps 429 → `RateLimited`, 5xx → `ServerError`, connection errors → `UpstreamTimeout`, other 4xx → `ClientError`, non-JSON → `MalformedResponse`. Rate limits, timeouts and 5xx are released with full-jitter backoff; client errors and malformed bodies fail without retry. `last_attempt_*`, `last_success_at` and `last_failure_*` are separate columns, and every attempt is a row in `refresh_attempts`.
4. **One busy account can't take every worker** — job middleware runs `RefreshLogContext` → `AccountCooldown` (after a 429 the account's jobs are deferred without calling upstream) → `AccountConcurrency` (at most `max_concurrency` running jobs per account, 2 in the workload).
5. **Scheduling** — profiles above 100,000 likes refresh every 24h, others every 72h. The scheduler claims a profile atomically (`refresh_queued_at`) before dispatching, so pending work is not queued twice.

Refresh jobs run on the `refresh` queue with a dedicated Horizon supervisor (4 fixed workers locally).

## Before and after: the same workload

`make workload MODE=legacy` then `make workload MODE=fixed` (seed 42, 4 workers):

- **Account A (busy):** 60 profiles including `madison420ivy` (seeded 120000 / rev 10), plus 10 duplicate jobs that bypass the pending claim. For the first 20s its upstream returns 60% 429 without `Retry-After`, 15% empty 500 and 10% responses delayed 12s (longer than the 10s HTTP timeout); after that every response is valid (121000 / rev 11, new format).
- **Account B (healthy):** 10 profiles, always valid, mixed old/new format, a new upstream revision every 5s, re-dispatched every 2s for the first 30s (150 jobs).

| Metric | Legacy A | Fixed A | Legacy B | Fixed B |
|---|---|---|---|---|
| Jobs dispatched | 70 | 70 | 150 | 150 |
| Upstream attempts | 70 | 78 | 150 | 150 |
| Attempts recorded as success | 70 | 60 | 150 | 61 |
| Attempts per success | 1.0 | 1.3 | 1.0 | 2.46 ¹ |
| Profiles with correct data at the end | **0 / 60** | **60 / 60** | 5 / 10 | 10 / 10 |
| Profiles zeroed or revision erased | 60 | 0 | 5 | 0 |
| First success after | 2s | 12s | **28s** | **2s** |
| Longest gap between successes | 11s | 18s | **28s** | **4s** |
| Oldest waiting job, max / p95 ² | 27.2s / 25.7s | 37.8s / 34.3s | 27.7s / 26.2s | 30.2s / 24.2s |

Stored data check: `madison420ivy` ends at **likes 0 / rev 11** with legacy and **121000 / rev 11** with the fix. No duplicate profile rows in either run. Fixed A outcomes: 60 success, 10 `stale_revision` (the duplicates), 6 `rate_limited`, 1 `server_error`, 1 `timeout`. Full reports: [legacy](docs/evidence/11-workload-legacy.txt) ([json](docs/evidence/11-workload-legacy.json)), [fixed](docs/evidence/12-workload-fixed.txt) ([json](docs/evidence/12-workload-fixed.json)).

What this shows:

- **Data:** the legacy run reports 220 successful jobs while zeroing 65 of 70 profiles; the fixed run keeps every stored value correct.
- **Isolation:** with legacy, A's slow responses held all 4 workers and B got nothing for 28s. With the fix, B's successes continued in every 5s window while A was rate limited, and A's cooldown cut its upstream calls during the 20s outage to 8 failed attempts.
- **Recovery:** after A's upstream recovered at 20s, all 60 A profiles converged to rev 11 by ~40s.

¹ B's extra attempts are `stale_revision`: repeated refreshes inside the same 5s upstream revision. They are correct no-ops, not failures.
² Seconds since the oldest job in the ready list was first dispatched. A released job keeps its original dispatch time, so for the fixed run this includes deliberate backoff; B's figure is mostly its own concurrency limiter deferring jobs for 2–5s (see remaining limits). Success timing is the better isolation signal.

Runs vary with jitter and worker timing: an earlier fixed run with the same seed took 60.9s (queue-wide oldest wait 44.8s), and an earlier legacy run gave B its first success at 25s. Each mode was measured once for the table above.

## Timeouts and duplicate processing

```
HTTP timeout 10s (connect 3s) < job $timeout 30s < Horizon supervisor timeout 60s < Redis retry_after 90s
```

- The job timeout (enforced with `pcntl`) must be shorter than `retry_after`. Otherwise Redis makes a still-running job visible again, and a second worker starts the same attempt.
- The supervisor timeout is the worker default for jobs that don't set their own; it also stays below `retry_after`.
- There is **no per-profile lock** today. If a job outlived `retry_after` (for example with `pcntl` missing), two workers could process the same profile. The conditional revision update is the only guard: a duplicate or late write becomes `stale_revision`, as the 10 duplicates in the workload show. The next step is `WithoutOverlapping($profileId)` with `expireAfter` longer than the job timeout.

## Setup

Requires Docker only. The containers run as UID/GID 1000 by default (`UID`/`GID` build args).

```bash
docker compose build
cp src/.env.example src/.env
docker compose run --rm composer install
docker compose run --rm artisan key:generate
docker compose up -d
docker compose run --rm artisan migrate
make test
```

- App: http://localhost/ · Horizon: http://localhost/horizon
- `upstream` serves the fake OnlyFans API at `http://upstream:8081/fake/api/users/{username}`; it is enabled only in that container.

## Commands

```bash
make reproduce                          # legacy and fixed handler side by side (MODE=legacy|fixed|both)
make workload MODE=legacy               # then MODE=fixed; reports in src/storage/app/workload-{mode}-{seed}.json
make art ARGS="profile:refresh madison420ivy"
make art ARGS="profiles:schedule-refreshes"
```

## Observability

- `storage/logs/refresh-*.log` (JSON) records `refresh.started` with `account_id`, `profile_id`, `username`, `job_uuid` and attempt numbers, plus Horizon long-wait warnings. Secret-like keys (`token`, `cookie`, `authorization`, …) are redacted.
- The failure and recovery trail for a job is the `refresh_attempts` table: `job_uuid`, `account_id`, `profile_id`, `outcome`, `http_status`, `revision`, `duration_ms`, `created_at`.

## Remaining limits and what is still broken

- **Real OnlyFans retrieval is not working.** Jobs only use `FakeUpstreamClient`; `RealOnlyFansClient` is not bound. `RequestSigner` is a placeholder (not OnlyFans' signing algorithm), and `ResponseMapper` defaults a missing likes value to 0 and uses snake_case field names where OnlyFans returns camelCase. No live request was verified.
- **Crash replay is not implemented.** `workload:crash-replay` sets a Redis flag that no job reads, so no worker is killed. Duplicate execution after a write is covered only by tests and the workload's duplicate jobs, which do not prove behaviour under a real worker crash, Redis failover losing reserved jobs, or container loss.
- **No per-profile lock** (see above).
- **The per-account concurrency limit is not atomic** (read then increment) and releases waiting jobs for 2–5s, which inflates the limited account's own queue age. A `Redis::funnel` would fix both.
- **The retry budget is time-based only.** Upstream attempts are counted but the 6-attempt limit is not enforced; `retryUntil` stops retries after 10 minutes.
- **Scheduling gaps:** a success does not clear `refresh_queued_at`, so a manual refresh is refused for up to an hour afterwards; the UI refresh button bypasses the claim; profiles with a null `next_refresh_at` are never scheduled. `ProfileWriter::createOrFirst()` is not race-safe (it is not used by the jobs).
- **Attempt metadata:** `queue_attempt` is always 1, `queued_at` holds the attempt time, and `giveUp()` counts a failure twice.
- **Logging is thin:** retries, failures and recovery are in `refresh_attempts`, not in the log.
- **Search** uses `LIKE`, not Scout.

## Scaling to 50 million jobs per day

579 jobs/s is only the average. Before choosing capacity, measure:

- peak arrivals per minute and per account, and the size of the largest accounts;
- job duration p50/p95/p99 and how much of it is upstream latency;
- retry amplification: attempts per success and releases per job (the workload already shows 1.3–2.5 attempts per success);
- Postgres write rate and connection count, Redis memory and ops/s;
- upstream rate limits per account.

**Likely first bottleneck:** per-attempt writes. Each attempt costs one `profiles` update plus one `refresh_attempts` insert, so at peak that is thousands of writes per second and 50M+ attempt rows per day; Horizon's per-job bookkeeping adds Redis memory on top. Deferral by release (cooldown and concurrency middleware) multiplies queue operations for rate-limited accounts.

**Evidence to collect:** run the workload at increasing worker counts and record `pg_stat_statements` write latency, `pg_stat_activity` connections, Redis `INFO memory`/`instantaneous_ops_per_sec`, and attempts per success.

**First change:** stop dispatching work that cannot run. Gate dispatch on a per-account token bucket so rate-limited accounts don't churn the queue, then batch or sample `refresh_attempts` writes (keep all failures, sample successes) and put PgBouncer in front of Postgres.

## Production plan

**First 15 minutes**

1. Horizon: `refresh` wait time, failed jobs, which accounts dominate the queue.
2. `refresh_attempts` for the last 15 minutes: outcomes by account, attempts per success.
3. Size the damage: profiles with `likes = 0` or `revision IS NULL` whose `last_success_at` falls inside the incident window.
4. If bad data is still being written, pause refreshes (`php artisan horizon:pause-supervisor supervisor-refresh`). Stale data is better than zeroed data.
5. Capture one raw upstream response to confirm the format change.

**Rollout**

Deploy the fixed code, then `php artisan horizon:terminate` so workers load it. Watch one account's profiles first: correct values, no `malformed` spike, attempts per success near baseline.

**When to roll back**

If `malformed` or `client_error` jumps (the new validation is rejecting valid responses), or verified values are wrong. Rolling back to the previous release would bring back the zeroing bug, so the rollback is to pause the refresh supervisor, keep the last valid data, and fix forward. `REFRESH_MODE=legacy` runs the broken handler and is never a rollback.

**Verify recovery**

Success rate per account back to baseline; no new profiles with `likes = 0` after a refresh; `madison420ivy` and a sample of large accounts match upstream; oldest waiting job back under the alert threshold; `next_refresh_at` advancing. Re-queue the profiles damaged during the incident (set `next_refresh_at = now()`) and confirm they converge.

## Stack

- PHP 8.4 (Alpine) · Laravel 13 · Horizon 5 · Scout 11 · Pest
- Postgres 17 · Redis 7.4
- Docker Compose: nginx, php-fpm, horizon, scheduler, fake upstream, postgres, redis

## Time spent

About **8h 40m** of wall-clock time on 15 September, from the first saved plan (11:07) to the last commit (19:45). This includes breaks and waiting on builds, and is more than the 6–7 hour budget. Phases, from file and commit timestamps:

| Phase | Time |
|---|---|
| Plan (`PLAN.md`) | 11:07–12:05 |
| Docker setup and review, including a DNS failure in Alpine image builds | 11:56–14:26 |
| Laravel scaffold, implementation and tests | 14:41–17:35 |
| Docs and CI | 17:35–18:30 |
| Audit gaps | 18:30–19:01 |
| Review, fixes, workload runs and this README | 19:01–19:45 |

## Documentation

| Doc | Description |
|-----|-------------|
| [API Reference](docs/API.md) | HTTP endpoints, Artisan commands, error responses, queue config |
| [Architecture](docs/ARCHITECTURE.md) | Component diagram, data flow, database schema, queue architecture, scaling |
| [Development Guide](docs/DEVELOPMENT.md) | Setup, project structure, code conventions, debugging tips |
| [Deployment Guide](docs/DEPLOYMENT.md) | Environment variables, production config, monitoring, backups |
| [Troubleshooting](docs/TROUBLESHOOTING.md) | Common issues and fixes for containers, tests, Horizon, Redis, Postgres |
| [Testing Guide](docs/TESTING.md) | How to run tests, test structure, mocking, writing new tests |
