> **Original plan, written before implementation.** Parts of it changed during the work (for example the Docker setup and some file names). The [README](README.md) describes what was actually built, measured and left unfinished.

# Plan: FansAPI take-home — OnlyFans profile refresh service (Laravel 13)

## Context
`/home/amanuel/pr/only` contains only `job.md`, a 6–7h take-home test. We need a small Laravel 13 service (Horizon, Redis, Scout) that fetches the `madison420ivy` profile in queue jobs. We then use it to reproduce an incident and fix it:
- the handler reads top-level `likes` and defaults it to 0;
- it marks every response as a success;
- the upstream moved `likes` into `profile{}`;
- the upstream also returns 429s and empty 500s.

Grading: 70% profile data retrieval, 15% queue recovery/isolation, 15% observability, scaling and explanation.

Decisions already made with the user:
- **Upstream:** a local fake upstream drives tests, the incident and the workload. A real OnlyFans client is built on a best-effort basis, with a timebox.
- **Git:** commit each step, so the broken handler plus failing tests is its own commit and can be checked out.
- **Runtime:** the user's own Docker Compose setup (nginx + PHP-FPM on `php:8.4-fpm-alpine`, app in `./src`), with the review fixes below, switched to `postgres:17-alpine` and a pinned Redis Alpine image. Nothing installed on the host.
- **Laravel Boost** as a dev-only dependency for AI-assisted development: version-matched docs search, DB schema/query, log reading, Laravel 13 guidelines. The app never depends on it at runtime.

## Architecture
- Repo root: `compose.yaml`, `dockerfiles/`, `Makefile`, `docs/`, `README.md`, `AI.md`, `PLAN.md`, `job.md`.
- The Laravel app lives in `./src`. All app paths below are relative to `src/`.
- Git repo at the root.

### Docker: the user's setup with review fixes
Validated on this machine:
- `docker compose config` currently fails because `mailpit` is undefined.
- UID/GID is 1000.
- SELinux is enforcing, but the Docker daemon doesn't apply SELinux labels, so bind mounts work without `:z`.
- Docker runs rootful, so published ports bypass firewalld. Bind them to `127.0.0.1`.

**`compose.yaml` changes**
- **Remove:**
  - the `mailpit` dependency;
  - the `queue-worker` service: a second consumer, PHP 8.2, `--timeout=90` equal to `retry_after`, runs as root;
  - the `laravel` service: exits immediately, duplicates the installer;
  - `phpmyadmin` and the unused `laravel-13` network;
  - the `:delegated` flags;
  - MariaDB's `tty`, `SERVICE_*` and `./mysql` bind mount;
  - `composer --ignore-platform-reqs`.
- **Shared `x-php` block:**
  - builds `php.dockerfile` with `UID`/`GID` args;
  - `image: onlyfans-php:dev`, so it's built once and reused;
  - mounts `./src:/var/www/html`;
  - `onlyfans` network.

| Service | Change / command | Notes |
|---|---|---|
| `app` (nginx) | ports `127.0.0.1:80:80`, `depends_on: php` only | UI and `/horizon` on http://localhost |
| `php` (FPM) | **remove `ports: 9000`** | FastCGI only on the internal network. Depends on healthy postgres and redis. |
| `horizon` | `php artisan horizon`, `restart: unless-stopped`, `stop_grace_period: 45s` | Grace period is longer than the 30s job timeout, so stops don't SIGKILL running jobs. Depends on healthy DBs. |
| `scheduler` (new) | `php artisan schedule:work`, `restart: unless-stopped` | Needed for the 24h/72h refresh scheduling |
| `upstream` (new) | `php artisan serve --host=0.0.0.0 --port=8081` | Fake OnlyFans server: `PHP_CLI_SERVER_WORKERS=16`, `FAKE_UPSTREAM_ENABLED=true`, internal only. Separate so slow fake responses don't use up the UI's FPM pool (5 children by default). |
| `postgres` (replaces `mysql`) | `postgres:17-alpine` | `POSTGRES_DB/USER=onlyfans`, named volume `pgdata`, `dockerfiles/postgres/init.sql` creates `testing`, `pg_isready` healthcheck, `127.0.0.1:5432` |
| `redis` | `redis:7.4-alpine`, `redis-server --appendonly yes` | Named volume `redisdata`, so queued and reserved jobs survive a restart (crash-replay needs this). `redis-cli ping` healthcheck, `127.0.0.1:6379`. |
| `composer` | `entrypoint: [composer]`, `profiles: [tools]` | Named volume for Composer cache |
| `artisan` | `entrypoint: [php, artisan]`, `profiles: [tools]` | |
| `laravel-installer` | kept, `profiles: [tools]` | Not used by the plan (`composer create-project` is non-interactive) |
| `adminer` (replaces phpMyAdmin) | `adminer` image, `profiles: [tools]`, `127.0.0.1:8080` | Works with Postgres |

`depends_on` uses `condition: service_healthy` for postgres and redis.

**`dockerfiles/php.dockerfile` changes**
```dockerfile
FROM php:8.4-fpm-alpine
ARG UID=1000
ARG GID=1000
WORKDIR /var/www/html
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN delgroup dialout \
 && addgroup -g ${GID} --system laravel \
 && adduser -G laravel --system -D -s /bin/sh -u ${UID} laravel

RUN apk add --no-cache git unzip libpq \
 && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql-dev linux-headers \
 && docker-php-ext-install pdo_pgsql pcntl opcache \
 && pecl install redis-6.2.0 && docker-php-ext-enable redis \
 && apk del .build-deps

COPY php/app.ini /usr/local/etc/php/conf.d/zz-app.ini   # memory_limit=256M
USER laravel
RUN composer global require laravel/installer
ENV PATH="/home/laravel/.composer/vendor/bin:$PATH"
CMD ["php-fpm"]
```
- **Dropped:**
  - the `-dev` packages left behind after the build;
  - unused extensions (gd, exif, calendar, bcmath, intl, zip) and the ones already built in (pdo, mbstring);
  - the unverified phpredis download with `curl`;
  - `composer:latest`;
  - the FPM `user` edits and `-R`, which are ignored when running as non-root.
- **Kept:** `posix` (enabled by default) and `pcntl`, which Horizon and job timeouts need.
- **`memory_limit=256M`** keeps PHP's limit above Horizon's `memory: 128`, so Horizon restarts workers before PHP crashes.

**Other files**
- `dockerfiles/nginx.dockerfile`: `ADD` → `COPY`, nothing else.
- `dockerfiles/nginx/default.conf`:
  - remove the `/phpmyadmin/` proxy;
  - use `$realpath_root` in `SCRIPT_FILENAME`;
  - add `location ~ /\.(?!well-known).* { deny all; }`.
- **Delete:** `dockerfiles/php.root.dockerfile` (unused, `php:8` + phpredis 5.3.4 won't build), `dockerfiles/queue.dockerfile`, `dockerfiles/laravel.dockerfile`.
- **Add:** `dockerfiles/php/app.ini`, `dockerfiles/postgres/init.sql`.

**`Makefile`**
- `make build` / `up` / `down` / `sh` (`docker compose exec php sh`)
- `make test`: `docker compose exec php php artisan test`
- `make art ARGS="…"`: `docker compose exec php php artisan …`
- `make composer ARGS="…"`: `docker compose run --rm composer …`
- `make workload MODE=legacy|fixed`, `make crash-replay`, `make zip`

Alpine has no bash, so scripts use `sh`.

```
app/Models/{Account, Profile, RefreshAttempt}.php
app/Refresh/{ProfilePayload, ProfileWriter, RefreshPolicy, RefreshDispatcher, Outcome, Backoff}.php
app/Refresh/Exceptions/{UpstreamException, RateLimited, UpstreamTimeout, ServerError, ClientError, MalformedResponse}.php
app/Upstream/{ProfileSource, FakeUpstreamClient}.php
app/Upstream/OnlyFans/{OnlyFansApiClient, RequestSigner, ResponseMapper}.php
app/Jobs/RefreshProfile.php                      # fixed job
app/Jobs/Legacy/LegacyRefreshProfile.php         # broken job, kept for before/after runs
app/Jobs/Middleware/{AccountCooldown, AccountConcurrency, RefreshLogContext}.php
app/Http/Controllers/{ProfileController, FakeUpstreamController}.php
app/Console/Commands/{ScheduleRefreshes, RefreshProfileCommand, ReproduceIncident, WorkloadRun, WorkloadCrashReplay}.php
app/Logging/RedactSecretsProcessor.php
config/refresh.php                               # timeouts, thresholds, backoff, limits (all env-driven)
tests/Fixtures/upstream/*.json                   # old/new format, zero/missing/negative/string likes, empty
../docs/evidence/                                # (repo root) saved test output, reproduction output, workload reports
../compose.yaml, ../dockerfiles/, ../Makefile, ../README.md, ../AI.md   # (repo root)
```

## Data model
Postgres notes:
- JSON columns are `jsonb`.
- Postgres has no unsigned integers, so migrations add `CHECK (likes >= 0)` and `CHECK (revision >= 1)` as a database-level backstop to the PHP validation.
- `createOrFirst` must never run inside a transaction: a unique violation aborts the whole Postgres transaction.

- **`accounts`**
  - `id`, `name`
  - `credentials` (`encrypted:array` cast, `$hidden`)
  - `max_concurrency` (default 2)
  - Meaning: an account is the upstream credential/session that makes the requests. Rate limits apply per account.
- **`profiles`**
  - Identity: `account_id`, `username` **unique**, `upstream_id` nullable unique.
  - Data: `likes` and `revision` (nullable unsigned bigint), `name`, `avatar_url`, `posts_count`, `photos_count`, `videos_count`, `profile_data` (JSON with the whitelisted remaining fields, never the raw body).
  - Refresh state: `last_attempt_at`, `last_attempt_outcome`, `last_success_at`, `last_failure_at`, `last_failure_reason`, `last_failure_detail` (500 chars), `consecutive_failures`, `next_refresh_at` (index), `refresh_queued_at`.
- **`refresh_attempts`**
  - `profile_id`, `account_id`, `job_uuid`, `queue_attempt`, `upstream_attempt`, `mode` (legacy|fixed), `outcome`, `http_status`, `revision`, `duration_ms`, `queued_at`, `created_at`.
  - Indexes on (`account_id`, `created_at`) and `job_uuid`.
  - All metrics come from this table, not from Horizon's completed count.

## Core components (the fix)
1. **`ProfilePayload::fromJson()`**
   - Reads `profile.likes` first, then top-level `likes`, detected with `array_key_exists`.
   - Missing likes → `MalformedResponse`. `likes` must be `is_int` and ≥ 0, so `0` is valid.
   - `revision` must be `is_int` and ≥ 1.
   - Whitelisted profile fields are type-checked (string|null, int ≥ 0|null).
2. **`ProfileWriter`**
   - `applySuccess()`: a single conditional `UPDATE … WHERE revision IS NULL OR revision < ?`. 1 row → success. 0 rows → `stale_revision`, and only attempt fields change.
   - `recordFailure()`: writes only attempt and failure fields.
   - `giveUp()`: clears `refresh_queued_at` and pushes `next_refresh_at` back (5 min × 2^n, capped at 6h).
   - Every method inserts a `refresh_attempts` row.
   - Profiles are created with `createOrFirst` on `username`.
3. **`RefreshPolicy::intervalFor()`**: `> 100_000` → 24h, otherwise 72h.
4. **`RefreshDispatcher::dispatchIfNotPending()`**
   - Claims the profile with an atomic `UPDATE profiles SET refresh_queued_at=now WHERE refresh_queued_at IS NULL OR < now-1h`, and dispatches only if 1 row changed.
   - Used by the scheduler (`profiles:schedule-refreshes`, every minute, `withoutOverlapping()->onOneServer()`, `select('id')->lazyById(500)` over due profiles), by the manual command and by the UI.
5. **Clients** (`ProfileSource` interface) turn transport problems into typed exceptions:

   | Upstream result | Exception |
   |---|---|
   | `ConnectionException` | `UpstreamTimeout` |
   | 429 | `RateLimited` (honours Retry-After if present, otherwise jitter) |
   | 5xx | `ServerError` |
   | Other 4xx | `ClientError` |
   | Non-array or empty body | `MalformedResponse` |

   - `FakeUpstreamClient` sends the account token as a header, which exercises log redaction.
   - `Http::timeout()` and `connectTimeout()` come from config. No `Http::retry()`.
6. **`RefreshProfile` job**
   - Constructor holds `int $profileId`. `$timeout` comes from config, and `retryUntil()` is dispatch + 10 min.
   - Middleware, in order: `RefreshLogContext` → `AccountCooldown` → `AccountConcurrency` (`Redis::funnel` per account, `limit(max_concurrency)`, release with jitter when full) → `WithoutOverlapping(profileId)->releaseAfter(jitter)->expireAfter(lock_expire)`.
   - Retryable failures (RateLimited/Timeout/ServerError): record the failure, bump the upstream-attempt counter (Redis `INCR refresh:upstream_attempts:{uuid}`, TTL 1 day), then `release(Backoff::fullJitter())`. After 6 upstream attempts, `fail()`.
   - `RateLimited` also sets the account cooldown key: 2s doubling to a 30s cap, with jitter.
   - Permanent failures (Malformed/ClientError): record the failure and `fail()`.
   - `failed()` calls `giveUp()`.
   - Releases caused by cooldown or concurrency don't use up the upstream retry budget; `retryUntil` bounds total time.
7. **`LegacyRefreshProfile`** keeps the broken logic exactly: `Http::get` with no status check, `$json['likes'] ?? 0`, always writes `likes`, `revision`, `last_success_at`, no middleware. `REFRESH_MODE=legacy|fixed` selects which job the dispatcher and workload use.
8. **Timeouts** (config/env): HTTP 10s (connect 3s) < job 30s < Horizon supervisor 60s < Redis `retry_after` 90s. Lock `expireAfter` is 120s, longer than the job timeout. The README explains each case: normal, missing pcntl, SIGKILL, and all guards failing, where the revision check wins.
9. **Horizon**: `refresh` supervisor, queue `refresh`, `balance=false`, `maxProcesses=4` (env), `memory=128`, `maxJobs=1000`, `maxTime=3600`, `memory_limit=64`, tight `trim`. `waits` threshold `redis:refresh => 30`, and a listener on `LongWaitDetected` writes a log line.
10. **Logging**: `refresh` channel, JSON, daily file.
    - `RefreshLogContext` adds `mode`, `account_id`, `profile_id`, `username`, `job_uuid`, `queue_attempt`, `upstream_attempt` to every line.
    - Events: `refresh.started`, `upstream.response` (status, duration_ms, outcome), `refresh.applied` (old/new revision), `refresh.stale`, `refresh.released` (reason, delay), `refresh.deferred` (cooldown or concurrency), `refresh.failed`, `account.cooldown_set`, `queue.long_wait`, plus `memory_peak_mb`.
    - `RedactSecretsProcessor` recursively scrubs `cookie`, `authorization`, `sign`, `x-bc`, `app-token`, `token`, `password`. Headers are never logged.
11. **Real OnlyFans client** (best-effort, 60 min timebox)
    - `GET https://onlyfans.com/api2/v2/users/{username}`.
    - `RequestSigner` builds `sign`/`time` headers from dynamic rules at a configurable `ONLYFANS_RULES_URL`, cached in Redis for 1h. Credentials (`cookie`, `x-bc`, `user-agent`) come from `.env` / encrypted account credentials.
    - `ResponseMapper` maps `favoritedCount` → likes plus the whitelisted fields. The real API has no revision, so revision = request start epoch-ms; this weakness goes in the README.
    - Try a quick live request during scaffolding to learn early whether it works. Whatever happens, record the status and result in the README and save a sanitized fixture if it works.
12. **Fake upstream** (`FakeUpstreamController`, routes only when enabled): `GET /fake/api/users/{username}`.
    - Per-account scenario stored in Redis: `format` (old|new|mixed), `rate_limit_until`, `p429`, `p500_empty`, `p_slow`, `slow_ms`, `latency_ms`, `seed`, `revision_mode` (time|static).
    - Responses are deterministic: `crc32(seed|username|requestN) % 100`.
    - With `time` revision mode, revision = base + floor(elapsed/5s), and likes are derived from it, so repeated refreshes see new data and verification at the end is exact.
13. **Scout + UI**
    - `Profile` uses `Searchable` with `SCOUT_DRIVER=database`. That engine reads the table directly, so query-builder updates never go stale.
    - One Blade page at `/` (inline CSS, no Vite): search, and a table showing likes, revision, last attempt/outcome, last success, last failure, next refresh, pending, with a "Refresh" button. `/horizon` for queues.

## Workload (starting values; tune, then record the final numbers)
- **Accounts**
  - **A (busy):** 60 profiles including `madison420ivy`, seeded with 120000 likes / rev 10.
  - **B (healthy):** 10 profiles.
- **Upstream behaviour**
  - A, first 20s: 60% 429 without Retry-After, 15% empty 500, 10% slow 3s, 15% ok, new format. After 20s: 100% ok.
  - B: 100% ok, 100ms latency, mixed formats.
- **Dispatch**
  - A: a burst of 60 through the dispatcher, plus 10 forced duplicate pushes that bypass the claim (tests the concurrency guard).
  - B: dispatcher every 500ms for 40s.
- **Settings:** 4 workers; per-account concurrency 2; HTTP timeout 1s; backoff 1s base, 8s cap; run until drained or 120s.
- **`workload:run --mode=legacy|fixed --seed=42`**
  - Resets data, sets scenarios, dispatches.
  - Samples the oldest waiting job every 500ms: `LINDEX queues:refresh 0`, age from Horizon's `pushedAt` (check the key name).
  - Waits, then writes `docs/evidence/workload-{mode}.json` and prints a table per account.
- **Report columns:** dispatched, upstream attempts, claimed successes, **verified successes** (stored likes/revision match fake truth), attempts per verified success, stale rejections, failures by outcome, max and p95 oldest-wait age, p50/p95 dispatch→success latency, successes per 5s timeline, profiles with wrong or zeroed data, duplicate username rows, peak worker memory.
- Restart Horizon between modes (`horizon:terminate`). Run each mode 3 times and report ranges, because jitter is random.
- **`workload:crash-replay`**
  - Pins one profile to static revision.
  - Sets a one-shot Redis flag (consumed with `GETDEL`). After `applySuccess` the job runs `posix_kill(getmypid(), SIGKILL)`.
  - The Horizon worker dies. The job stays reserved until `retry_after` (90s) and the lock until 120s, then runs again with the same job uuid.
  - The command polls `refresh_attempts` and prints the timeline. Expected: attempt 1 `success`, then attempt 2 `stale_revision`, profile unchanged.
  - A PHPUnit test covers the same idempotency without Docker.
  - The README says what this doesn't prove: container/host loss, Redis failover losing reserved jobs, a DB commit acknowledged but lost, a job running past lock expiry.

## Commit sequence (with time estimates)
0. **Update `/home/amanuel/pr/only/PLAN.md`** with this revised plan (the user asked for it in the project). Wait for the user's go-ahead before step 1.
1. **Docker fixes and scaffold (60m)**
   - Apply the Docker review fixes above. Check `docker compose config --quiet` passes, then `docker compose build`.
   - **Run `mkdir src` on the host first.** Otherwise Docker creates the bind-mount folder owned by root, and the `laravel` user (UID 1000) can't write to it.
   - Create the app: `docker compose run --rm composer create-project laravel/laravel . "^13.0"`.
   - `src/.env`:
     - `DB_CONNECTION=pgsql`, `DB_HOST=postgres`, `DB_DATABASE=onlyfans`, `DB_USERNAME=onlyfans`
     - `REDIS_CLIENT=phpredis`, `REDIS_HOST=redis`
     - `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`
     - `FAKE_UPSTREAM_URL=http://upstream:8081`
   - `make composer ARGS="require laravel/horizon laravel/scout"`, then `horizon:install`, publish the Scout config, write `config/refresh.php`.
   - `docker compose up -d`, then check `docker compose exec php php -m | grep -E 'pcntl|posix|redis|pdo_pgsql'`.
   - Quick live check against the real OnlyFans API.
   - Laravel Boost:
     - `make composer ARGS="require laravel/boost --dev"`.
     - `docker compose exec php php artisan boost:install` (interactive, so the user may need to run it in a terminal). Choose Claude Code, guidelines and MCP.
     - Set `.mcp.json` to command `docker` with args `["compose","exec","-T","php","php","artisan","boost:mcp"]`. `-T` is needed because MCP talks over stdio without a TTY.
     - Gitignore `.mcp.json`, `CLAUDE.md`, `AGENTS.md` and `boost.json`.
     - Restart the Claude Code session so the MCP tools load.
   - `git init` at the repo root. The root `.gitignore` excludes nothing extra; databases live in named volumes.
   - Verify:
     - `docker compose ps` shows all services healthy or running;
     - http://localhost/ and http://localhost/horizon load;
     - `make test` is green;
     - `ss -tlnp` shows 5432, 6379 and 80 only on 127.0.0.1, and nothing on 9000;
     - Boost shows as connected in `/mcp`.
2. **Reproduce the bug (60m)**
   - Migrations and models, fixtures, fake upstream, `LegacyRefreshProfile`.
   - `incident:reproduce` runs old format / new format / 429 / empty 500 through the handler and prints the stored fields and outcome.
   - Write the regression tests for correct behaviour; they **fail**.
   - Save `docs/evidence/01-reproduction-legacy.txt` and `02-failing-tests.txt`.
3. **Data fix (75m)**
   - `ProfilePayload`, `ProfileWriter`, `RefreshPolicy`, exceptions, clients, `RefreshProfile`.
   - Tests pass; save `03-passing-tests.txt`. A characterization test keeps the legacy bug reproducible.
4. **Scheduling (30m)**: dispatcher claim, scheduler command, 24h/72h, reclaim, give-up backoff, tests.
5. **Queue resilience (60m)**: timeouts, overlap lock, cooldown and concurrency middleware, retry budget, jitter, tests.
6. **Observability (30m)**: JSON channel, context, redaction processor, `LongWaitDetected` listener, redaction test.
7. **Workload and crash replay (60m)**: commands, legacy and fixed runs, reports saved to `docs/evidence`.
8. **Real OnlyFans client (≤60m, timeboxed)**: signer, mapper, fixture, result documented.
9. **UI and Scout search (20m)**
10. **Docs (45m)**
    - README: failure, evidence, fix, before/after table, timeout relationship, isolation, crash-replay limits, 50M jobs/day scaling, production plan (first 15 min / rollout / rollback / verify), remaining limits, time spent.
    - `AI.md`: Claude Code plus Laravel Boost (docs search, schema/log tools); what was checked by hand; incorrect suggestions caught; what wasn't verified.
    - `git archive --format=zip -o Firstname_Lastname.zip HEAD`. Confirm the user's full name when submitting.

If time runs short, cut from the bottom: UI polish, then the real client, then the 3× workload repeats. Never cut the data-fix tests.

## Tests (PHPUnit, Postgres `testing` DB, Redis DB 15, run with `make test`)
- `Unit/ProfilePayloadTest`: both formats; likes `0` ok; missing, `null`, `-1`, `"abc"`, `"121000"`, `1.5` rejected; missing revision rejected.
- `Unit/RefreshPolicyTest`: 0, 99_999, 100_000 → 72h; 100_001 → 24h.
- `Feature/RefreshProfileJobTest` (`Http::fake` with fixtures), seeded at 120000 / rev 10:
  - new format → 121000 / rev 11;
  - 429 without Retry-After → released, data and `last_success_at` intact, failure fields set;
  - empty 500 → same as 429;
  - timeout → released;
  - malformed → failed, data intact;
  - 404 → failed, no retry;
  - retry budget exhausted → `giveUp`.
- `Feature/OutOfOrderRevisionTest`: apply rev 11, then rev 10 → still rev 11, `stale_revision`.
- `Feature/DuplicateExecutionTest`: same job handled twice → one row, one success, one stale; `createOrFirst` race → one row.
- `Feature/ScheduleRefreshesTest`: due selection, double run pushes once (`Queue::assertPushed(..., 1)`), stale claim reclaimed.
- `Feature/AccountIsolationTest`: cooldown releases with `Http::assertNothingSent()`; concurrency limit releases the extra job; account B unaffected.
- `Feature/LogRedactionTest`: the secret token never appears in the log output.
- `Feature/Legacy/LegacyBehaviourTest`: documents that the broken handler stores 0 and marks success.

## Verification (end-to-end)
1. `make build up && make art ARGS="migrate:fresh --seed" && make test`: all green, output saved. `docker compose config --quiet` passes.
2. `git checkout <commit 2> && make test`: the regression tests fail. Return to `main`.
3. `make art ARGS="incident:reproduce --mode=legacy"` and `--mode=fixed`: side-by-side stored fields.
4. `make workload MODE=legacy`, then `make workload MODE=fixed` (seed 42). Compare the reports:
   - B keeps succeeding during A's rate-limit window;
   - A recovers after 20s;
   - `madison420ivy` ends at the correct likes/revision (never 0);
   - no duplicate usernames;
   - lower oldest-wait age.
5. `make crash-replay`: worker killed, same uuid redelivered, second attempt `stale_revision`, data unchanged.
6. `grep` the refresh log for one `job_uuid` to follow a single attempt from failure through recovery; check that no secrets appear.
7. Open http://localhost/ (search via Scout) and http://localhost/horizon while the workload runs.
8. `docker compose restart horizon` during a run: running jobs finish within the 45s grace period, with no duplicate `success` rows for the same job uuid.
