# OnlyFans Profile Refresh Service

Laravel 13 service that refreshes OnlyFans profiles through Horizon queue workers. It fetches public profiles such as `madison420ivy` from the real OnlyFans API. A local fake upstream reproduces and fixes an incident: the API moved `likes` into a `profile{}` object and started returning 429s without `Retry-After` and empty 500s.

## The failure

The original handler (`App\Jobs\Legacy\LegacyRefreshProfile`, kept only for reproduction):

- reads `$json['likes'] ?? 0`, so the new format stores `likes = 0`;
- never checks the HTTP status, so a 429 or 500 is parsed as a profile and overwrites the last valid data;
- writes every response as a successful refresh, with no revision check, so an older response arriving last replaces newer data.

Workers therefore report completed jobs while profiles show zeroed or stale data.

## Evidence

Everything below was produced inside the project containers. Files are in [docs/evidence](docs/evidence).

**Live retrieval** ([05-live-onlyfans.txt](docs/evidence/05-live-onlyfans.txt), `make live`). One signed, logged-out request through Horizon stored:

- `madison420ivy` with OnlyFans id 5140520, name Madison Ivy
- likes (`favoritedCount`) 606,525
- 174 posts, 573 photos, 216 videos
- join date and verification status

The next refresh is 24h later, because the profile is above 100,000 likes.

**Reproduction** ([01-reproduction.txt](docs/evidence/01-reproduction.txt), `make reproduce`). Each case starts from the last valid state: likes 120000, revision 9.

| Upstream response | Legacy handler stores | Fixed handler stores |
|---|---|---|
| 200 `{likes:120000, revision:10}` | 120000 / rev 10, success | 120000 / rev 10, success |
| 200 `{profile:{likes:121000}, revision:11}` | **0** / rev 11, success | 121000 / rev 11, success |
| 429, no `Retry-After` | **0** / rev **null**, success | 120000 / rev 9 kept, `rate_limited` |
| 500, empty body | **0** / rev **null**, success | 120000 / rev 9 kept, `server_error` |
| rev 11, then rev 10 arrives last | **120000 / rev 10** (overwritten), success | 121000 / rev 11 kept, `stale_revision` |

**Crash replay** ([06-crash-replay.txt](docs/evidence/06-crash-replay.txt), `make crash`):

1. The Horizon worker is killed with `SIGKILL` right after writing revision 11, before acknowledging the job.
2. 90s later (`retry_after`) Redis hands the **same job** (same `job_uuid`) to another worker.
3. The per-profile lock left by the dead worker defers it until the lock expires at 120s.
4. It then records `stale_revision`; the data stays 121000 / rev 11.

**Tests** ([02-tests.txt](docs/evidence/02-tests.txt)): 101 passing, on SQLite locally and on Postgres, and in both GitHub Actions jobs. They call the real code for:

- both formats;
- invalid `likes` (missing, negative, string, float) and a missing revision;
- 429 (including `Retry-After`), 500 and timeout releases;
- the retry budget;
- duplicate execution and an older revision arriving last;
- the create race on the unique username;
- the 100,000-likes boundary (exactly 100,000 is 72h);
- pending-claim handling and the per-account concurrency limit;
- OnlyFans request signing, pinned to a known signature value;
- the live client's error mapping, against a trimmed real response fixture;
- the legacy handler's bugs.

## The fix

1. **Validate before writing** — `ProfilePayload::fromJson()` reads `profile.likes`, then top-level `likes`. A missing `likes` is invalid, `0` is valid, and negative, string or float values are rejected. Validation runs inside the job's `try`, so an invalid body is recorded as `malformed` and never touches stored data.
2. **Only newer data wins** — `ProfileWriter::applySuccess()` is one conditional `UPDATE … WHERE revision IS NULL OR revision < ?`. Zero rows means `stale_revision`, and only the attempt fields change. `username` is unique, and `createOrFirst()` recovers from the unique violation when two workers create the same profile.
3. **Failures never look like success** — each failure type is handled differently:
   - 429 → `rate_limited`, retried; honours `Retry-After`;
   - connection error → `timeout`, retried;
   - 5xx → `server_error`, retried;
   - 401/403 from OnlyFans → `signature_rejected`, retried with fresh rules;
   - other 4xx → `client_error`, fails without retry;
   - invalid body → `malformed`, fails without retry.

   Retries use full-jitter backoff and stop after 6 upstream attempts, or 10 minutes. `last_attempt_*`, `last_success_at` and `last_failure_*` are separate columns, and every attempt is a row in `refresh_attempts`.
4. **One busy account can't take every worker** — middleware, in order:
   - `AccountCooldown`: after a 429, the account's jobs wait without calling upstream;
   - `WithoutOverlapping` per profile;
   - `AccountConcurrency`: an atomic `Redis::funnel` slot per account, 2 in the workload.
5. **Scheduling** — above 100,000 likes refresh every 24h, others every 72h; never-refreshed profiles are due immediately. The scheduler and the UI claim a profile atomically (`refresh_queued_at`) before dispatching, and a finished refresh clears the claim.
6. **Real OnlyFans client** — `RealOnlyFansClient` signs requests with OnlyFans' dynamic rules:
   - the signature is `sha1(static_param, time, path, user id)` plus a checksum, formatted with the rules' prefix and suffix;
   - rules come from the community-maintained [DATAHOARDERS/dynamic-rules](https://github.com/DATAHOARDERS/dynamic-rules), cached for 5 minutes and dropped on 401/403;
   - `ResponseMapper` keeps only public profile fields, and a missing `favoritedCount` is `malformed`, never 0;
   - each account's `source` picks the upstream: `onlyfans` or the local `fake`.

Refresh jobs run on the `refresh` queue with a dedicated Horizon supervisor (4 fixed workers locally).

## Before and after: the same workload

`make workload MODE=legacy` then `make workload MODE=fixed` (seed 42, 4 workers):

- **Account A (busy):** 60 profiles including `madison420ivy` (seeded 120000 / rev 10), plus 10 duplicate jobs that bypass the pending claim.
  - First 20s: 60% 429 without `Retry-After`, 15% empty 500, 10% responses delayed 12s (longer than the 10s HTTP timeout).
  - After that every response is valid: 121000 / rev 11, new format.
- **Account B (healthy):** 10 profiles, always valid, mixed old/new format, a new upstream revision every 5s. Re-dispatched every 2s for the first 30s (150 jobs).

| Metric | Legacy A | Fixed A | Legacy B | Fixed B |
|---|---|---|---|---|
| Jobs dispatched | 70 | 70 | 150 | 150 |
| Upstream attempts | 70 | 76 | 150 | 150 |
| Attempts recorded as success | 70 | 60 | 150 | 66 |
| Attempts per success | 1.0 | 1.27 | 1.0 | 2.27 ¹ |
| Profiles with correct data at the end | **0 / 60** | **60 / 60** | 5 / 10 | 10 / 10 |
| Profiles zeroed or revision erased | 60 | 0 | 5 | 0 |
| First success after | 2s | 8s | **27s** | **1s** |
| Longest gap between successes | 11s | 15s | **27s** | **5s** |
| Oldest waiting job, max / p95 ² | 26.7s / 25.2s | 40.3s / 25.8s | 27.2s / 25.7s | **13.6s / 8.1s** |

**Stored data check:**
- `madison420ivy` ends at **likes 0 / rev 11** with legacy and **121000 / rev 11** with the fix.
- No duplicate profile rows in either run.
- Fixed A outcomes: 60 success, 10 `stale_revision` (the duplicates), 5 `rate_limited`, 1 `timeout`.

Reports: [legacy](docs/evidence/03-workload-legacy.txt) ([json](docs/evidence/03-workload-legacy.json)), [fixed](docs/evidence/04-workload-fixed.txt) ([json](docs/evidence/04-workload-fixed.json)).

What this shows:

- **Data:** the legacy run reports 220 successful jobs while zeroing 65 of 70 profiles; the fixed run keeps every stored value correct.
- **Isolation:** with legacy, A's slow responses held all 4 workers and B got nothing for 27s. With the fix, B succeeded in every 5s window while A was failing. A made only 6 failed upstream calls during its outage (all in the first 17s), because its cooldown and concurrency limit kept the rest of its jobs waiting.
- **Recovery:** after A's upstream recovered at 20s, all 60 A profiles converged to rev 11; the last success was at 58s.

¹ B's extra attempts are `stale_revision`: repeated refreshes inside the same 5s upstream revision. They are correct no-ops, not failures.
² Seconds since the oldest job in the ready list was first dispatched. A released job keeps its original dispatch time, so A's figure includes its deliberate backoff.

Each mode was measured once for this table, and timings vary between runs. Before the atomic funnel and profile lock were added, the fixed run took 39.3s and B's oldest waiting job was 30.2s.

## Timeouts and duplicate processing

```
HTTP timeout 10s (connect 3s) < job $timeout 30s < Horizon supervisor timeout 60s < Redis retry_after 90s
account funnel slot 60s > job timeout;  profile lock 120s > retry_after
```

- **Job timeout below `retry_after`.** The job timeout is enforced with `pcntl`. If it were longer than `retry_after`, Redis would make a still-running job visible again and a second worker would start the same attempt.
- **Supervisor timeout.** It's the worker default for jobs that don't set their own, and it also stays below `retry_after`.
- **Two workers on one profile.** `WithoutOverlapping($profileId)` prevents it; a duplicate is released until the lock is free. The lock only expires on its own (120s) when a worker died holding it, which is exactly what the crash replay shows.
- **Last line of defence.** If everything else fails, the conditional revision update turns a duplicate or late write into `stale_revision`.

**What the crash replay proves:** a worker process killed after the database write but before the acknowledgement leads to a redelivery of the same job, and that redelivery changes nothing.

**What it does not prove:**
- losing a whole container or host;
- Redis failover losing reserved jobs (Redis here uses an append-only file on a single node);
- a database commit that is acknowledged and then lost;
- a job outliving the 120s lock while still running.

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
- No Node dependencies are used; the UI is a single Blade page.

## Commands

```bash
make live                               # fetch madison420ivy from OnlyFans through Horizon (OF_USER=… for another profile)
make reproduce                          # legacy and fixed handler side by side (MODE=legacy|fixed|both)
make workload MODE=legacy               # then MODE=fixed; reports in src/storage/app/workload-{mode}-{seed}.json
make crash                              # kill a worker after its write and verify the replay (~2 minutes)
make art ARGS="profiles:schedule-refreshes"
```

`make workload` truncates `accounts`, `profiles` and `refresh_attempts` in the dev database.

## Observability

`storage/logs/refresh-*.log` is JSON. Every line carries `account_id`, `profile_id`, `username`, `job_uuid`, `queue_attempt` and `upstream_attempt`.

Events:
- `refresh.started`
- `refresh.applied` / `refresh.stale` (old and new revision, duration)
- `refresh.released` (outcome, HTTP status, delay)
- `refresh.failed` (permanent or retry budget spent)
- `refresh.deferred` (account cooldown or concurrency)
- `account.cooldown_set`
- `crash_injection.sigkill`
- Horizon long-wait warnings

Following one `job_uuid` through the log shows the whole failure and recovery; the crash replay trail is in its evidence file. Credentials are only sent as request headers, never logged; secret-like keys are also redacted by a log processor. The same trail is queryable in `refresh_attempts`.

## Remaining limits

- **Live retrieval depends on outside rules.** It needs community-maintained signing rules and OnlyFans continuing to answer logged-out requests; it was verified with single requests on 15 September 2026.
  - OnlyFans has no revision field, so the live revision is the request start time in milliseconds. Late responses are ordered by when they were requested, not by an upstream version.
  - Logged-in requests (cookie, `user_id`) are supported by the client but untested.
- **Deferrals churn the queue.** Deferred jobs (cooldown, concurrency, lock) are re-queued with a delay, and each release counts as a queue attempt: the crash replay shows attempt 15. Lock deferrals are not logged individually.
- **Tests vs production stack.** The local test suite uses SQLite and the sync queue. Horizon and Redis behaviour is shown by the workload and crash replay, each run once.
- **CI covers the test suite only.** GitHub Actions runs it on Postgres and inside the Docker stack (both green for `350a558`, 101 tests each); the workload, live fetch and crash replay are not run in CI.
- **Horizon dashboard access.** Outside the local environment it uses the default `viewHorizon` gate, which allows nobody until emails are configured in `HorizonServiceProvider`.

## Scaling to 50 million jobs per day

579 jobs/s is only the average. Before choosing capacity, measure:

- peak arrivals per minute and per account, and the size of the largest accounts;
- job duration p50/p95/p99 and how much of it is upstream latency (the live request took about 1.1s);
- retry amplification: attempts per success (1.27–2.27 in the workload) and releases per job;
- Postgres write rate and connection count, Redis memory and ops/s;
- upstream limits per account and per IP, and how often the signing rules rotate.

**Likely first bottleneck:** upstream capacity and per-attempt writes.
- At ~1s per live request, 579 jobs/s needs roughly 600 concurrent workers before retries, far beyond one host.
- Every attempt costs one `profiles` update and one `refresh_attempts` insert, which is 50M+ attempt rows per day.
- Horizon's per-job bookkeeping adds Redis memory on top.
- Deferral by release multiplies queue operations for rate-limited accounts.

**Evidence to collect:** run the workload at increasing worker counts and record:
- write latency (`pg_stat_statements`) and connections (`pg_stat_activity`);
- Redis `INFO memory` and `instantaneous_ops_per_sec`;
- attempts per success and upstream 429 rate per account.

**First change:** stop dispatching work that cannot run. Gate dispatch on a per-account token bucket, so rate-limited accounts don't churn the queue. Then batch or sample `refresh_attempts` writes (keep all failures, sample successes), and put PgBouncer in front of Postgres.

## Production plan

**First 15 minutes**

1. Horizon: `refresh` wait time, failed jobs, which accounts dominate the queue.
2. `refresh_attempts` for the last 15 minutes: outcomes by account, attempts per success, any spike in `signature_rejected`.
3. Size the damage: profiles with `likes = 0` or `revision IS NULL` whose `last_success_at` falls inside the incident window.
4. If bad data is still being written, pause refreshes (`php artisan horizon:pause-supervisor supervisor-refresh`). Stale data is better than zeroed data.
5. Capture one raw upstream response to confirm the format change.

**Rollout**

Deploy the fixed code, then `php artisan horizon:terminate` so workers load it. Watch one account's profiles first: correct values, no `malformed` spike, attempts per success near baseline.

**When to roll back**

If `malformed` or `client_error` jumps (the new validation is rejecting valid responses), or verified values are wrong. Rolling back to the previous release would bring back the zeroing bug, so the rollback is to pause the refresh supervisor, keep the last valid data, and fix forward. `REFRESH_MODE=legacy` runs the broken handler and is never a rollback.

**Verify recovery**

- Success rate per account back to baseline.
- No new profiles with `likes = 0` after a refresh.
- `madison420ivy` and a sample of large accounts match upstream.
- Oldest waiting job back under the alert threshold, and `next_refresh_at` advancing.
- Re-queue the profiles damaged during the incident (set `next_refresh_at = now()`) and confirm they converge.

## Stack

- PHP 8.4 (Alpine) · Laravel 13 · Horizon 5 · Scout 11 (database engine) · Pest
- Postgres 17 · Redis 7.4
- Docker Compose: nginx, php-fpm, horizon, scheduler, fake upstream, postgres, redis

## Time spent

About **9h 30m** of wall-clock time on 15 September, from the first saved plan (11:07) to the final changes (about 20:40). This includes breaks and waiting on builds, and is more than the 6–7 hour budget. Phases, from file and commit timestamps:

| Phase | Time |
|---|---|
| Plan (`PLAN.md`) | 11:07–12:05 |
| Docker setup and review, including a DNS failure in Alpine image builds | 11:56–14:26 |
| Laravel scaffold, implementation and tests | 14:41–17:35 |
| Docs and CI | 17:35–18:30 |
| Audit gaps | 18:30–19:01 |
| Review, fixes, workload runs | 19:01–19:45 |
| Second review: live OnlyFans client, crash replay, lock and funnel, test fixes, cleanup | 19:50–20:40 |
