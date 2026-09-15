# Architecture

## High-Level Component Diagram

```
┌─────────────────────────────────────────────────────────────┐
│                      Docker Compose                         │
│                                                             │
│  ┌──────────┐   ┌──────────┐   ┌──────────────────────┐   │
│  │   app    │   │   php    │   │      horizon         │   │
│  │ (nginx)  │──▶│ (fpm)    │   │ (queue worker)       │   │
│  │  :80     │   │          │   │                      │   │
│  └──────────┘   └────┬─────┘   └──────────┬───────────┘   │
│                      │                     │                │
│                      │         ┌───────────┴───────────┐   │
│                      │         │                       │   │
│  ┌──────────┐   ┌────▼─────┐  │   ┌───────────────┐   │   │
│  │scheduler │   │ postgres │  │   │    redis       │   │   │
│  │(cron)    │   │  :5432   │◀─┘──▶│    :6379       │   │   │
│  └──────────┘   └──────────┘      └───────────────┘   │   │
│                                      ▲                  │   │
│  ┌──────────┐                        │                  │   │
│  │ upstream │────────────────────────┘                  │   │
│  │(fake API)│   reads/writes fake:scenario:* keys       │   │
│  │  :8081   │                                          │   │
│  └──────────┘                                          │   │
└─────────────────────────────────────────────────────────────┘
```

**Seven services:**

| Service      | Role                              | Port         |
| ------------ | --------------------------------- | ------------ |
| `app`        | Nginx reverse proxy               | 80 (public)  |
| `php`        | PHP-FPM (web requests)            | 9000 (internal) |
| `horizon`    | Queue worker (Horizon supervisor) | none         |
| `scheduler`  | Task scheduler (cron)             | none         |
| `upstream`   | Fake OnlyFans API                 | 8081 (internal) |
| `postgres`   | Database                          | 5432 (localhost) |
| `redis`      | Cache + queue + coordination      | 6379 (localhost) |

---

## Data Flow: Profile Refresh

```
1. TRIGGER
   ├─ Manual:     POST /profiles/{id}/refresh
   ├─ Scheduler:  profiles:schedule-refreshes (every minute)
   └─ CLI:        profile:refresh {username}

2. DISPATCH
   RefreshDispatcher::dispatchIfNotPending()
   ├─ DB claim:   UPDATE profiles SET refresh_queued_at=now()
   │              WHERE id=? AND (refresh_queued_at IS NULL OR < 1h ago)
   ├─ If claim=0: return false (already pending)
   └─ If claim=1: RefreshProfile::dispatch(profileId, mode)
                   → queued on "refresh" queue

3. MIDDLEWARE PIPELINE (before job runs)
   ├─ RefreshLogContext   → logs refresh.started to refresh channel
   ├─ AccountCooldown     → checks Redis cooldown key, releases 1-3s if active
   └─ AccountConcurrency  → checks Redis concurrency counter, releases 2-5s if at limit

4. UPSTREAM FETCH
   ├─ FakeUpstreamClient::fetch() or RealOnlyFansClient::fetch()
   ├─ HTTP GET → upstream API
   └─ Maps response to exceptions:
       ├─ 429       → RateLimited
       ├─ 5xx       → ServerError
       ├─ 4xx       → ClientError
       ├─ timeout   → UpstreamTimeout
       └─ bad JSON  → MalformedResponse

5. ERROR HANDLING (per exception type)
   ├─ RateLimited / UpstreamTimeout / ServerError
   │   ├─ ProfileWriter::recordFailure()
   │   ├─ AccountCooldown::setCooldown()
   │   ├─ Backoff::fullJitter() → delay
   │   └─ $job->release(delay) → re-queued
   │
   ├─ ClientError / MalformedResponse
   │   ├─ ProfileWriter::recordFailure()
   │   └─ $job->fail() → moved to failed_jobs
   │
   └─ Success
       ├─ ProfilePayload::fromJson() → validates likes, revision
       ├─ ProfileWriter::applySuccess()
       │   ├─ DB atomic: UPDATE profiles SET ... WHERE revision < payload.revision
       │   ├─ If updated=0 → outcome="stale_revision" (no data change)
       │   └─ If updated=1 → outcome="success", reset consecutive_failures
       ├─ AccountCooldown::clearCooldown()
       └─ ProfileWriter::logAttempt() → INSERT INTO refresh_attempts

6. NEXT SCHEDULE
   RefreshPolicy::nextRefreshAt(likes)
   ├─ likes > 100,000 → next_refresh_at = now + 24h
   └─ likes <= 100,000 → next_refresh_at = now + 72h
```

---

## Database Schema

### `accounts`

| Column            | Type         | Notes                             |
| ----------------- | ------------ | --------------------------------- |
| `id`              | bigint (PK)  |                                   |
| `name`            | string       | Display name                      |
| `credentials`     | text         | Encrypted array (token, cookie, etc.) |
| `max_concurrency` | unsigned int | Default 2. Max parallel jobs per account |
| `created_at`      | timestamp    |                                   |
| `updated_at`      | timestamp    |                                   |

### `profiles`

| Column                  | Type         | Notes                                    |
| ----------------------- | ------------ | ---------------------------------------- |
| `id`                    | bigint (PK)  |                                          |
| `account_id`            | FK → accounts | CASCADE delete                          |
| `username`              | string       | UNIQUE                                   |
| `upstream_id`           | bigint       | UNIQUE, nullable                         |
| `likes`                 | bigint       | Upstream likes count                     |
| `revision`              | bigint       | Upstream revision number                 |
| `name`                  | string       | Nullable                                 |
| `avatar_url`            | string       | Nullable                                 |
| `posts_count`           | unsigned int | Nullable                                 |
| `photos_count`          | unsigned int | Nullable                                 |
| `videos_count`          | unsigned int | Nullable                                 |
| `profile_data`          | jsonb        | Whitelisted remaining fields             |
| `last_attempt_at`       | timestamp    |                                          |
| `last_attempt_outcome`  | string       | success, stale_revision, rate_limited, timeout, server_error, client_error, malformed |
| `last_success_at`       | timestamp    |                                          |
| `last_failure_at`       | timestamp    |                                          |
| `last_failure_reason`   | string       |                                          |
| `last_failure_detail`   | string(500)  |                                          |
| `consecutive_failures`  | unsigned int | Default 0                                |
| `next_refresh_at`       | timestamp    | INDEXED — scheduler queries this         |
| `refresh_queued_at`     | timestamp    | Prevents duplicate dispatches            |
| `created_at`            | timestamp    |                                          |
| `updated_at`            | timestamp    |                                          |

### `refresh_attempts`

| Column            | Type         | Notes                              |
| ----------------- | ------------ | ---------------------------------- |
| `id`              | bigint (PK)  |                                    |
| `profile_id`      | FK → profiles | CASCADE delete                    |
| `account_id`      | FK → accounts | CASCADE delete                    |
| `job_uuid`        | uuid         | INDEXED                            |
| `queue_attempt`   | unsigned int | How many times the job was requeued |
| `upstream_attempt`| unsigned int | How many HTTP calls were made     |
| `mode`            | string       | "legacy" or "fixed"                |
| `outcome`         | string       | success, stale_revision, rate_limited, timeout, server_error, client_error, malformed |
| `http_status`     | smallint     | Nullable                           |
| `revision`        | bigint       | Nullable                           |
| `duration_ms`     | unsigned int | Nullable                           |
| `queued_at`       | timestamp    |                                    |
| `created_at`      | timestamp    |                                    |

**Indexes:**
- `(account_id, created_at)` — workload report queries
- `job_uuid` — crash replay correlation

---

## Queue Architecture

### Horizon Supervisors

The `horizon` service runs `php artisan horizon`, which starts a Horizon supervisor managing worker processes on the `refresh` queue.

**Worker lifecycle:**

```
Horizon Supervisor
  ├─ Worker 1 (processing refresh queue)
  ├─ Worker 2 (processing refresh queue)
  └─ ... (scales based on Horizon config)
```

### Middleware Pipeline

Every `RefreshProfile` job passes through three middleware layers:

```
┌─────────────────────────────────────────────────────────────┐
│                    Job Middleware Pipeline                   │
│                                                             │
│  1. RefreshLogContext                                       │
│     └─ Logs structured entry to 'refresh' channel           │
│        { mode, account_id, profile_id, username,            │
│          job_uuid, queue_attempt, upstream_attempt }        │
│                                                             │
│  2. AccountCooldown                                         │
│     └─ Redis GET refresh:cooldown:account:{id}              │
│        ├─ If cooldown active → release(1-3s)                │
│        └─ If expired/missing → pass through                 │
│                                                             │
│  3. AccountConcurrency                                      │
│     └─ Redis GET refresh:concurrency:account:{id}           │
│        ├─ If count >= max_concurrency → release(2-5s)       │
│        └─ If under limit → incr, execute, decr in finally   │
└─────────────────────────────────────────────────────────────┘
```

### Retry Behavior

The job uses `$tries = max_upstream_attempts + 1` (default 7) and `$maxExceptions = max_upstream_attempts` (default 6). Combined with `retryUntil()` returning 10 minutes, jobs are retried via `$job->release(delay)` rather than exception-based retries.

```
Attempt 1: upstream call
  ├─ 429/500/timeout → release Backoff::fullJitter(1) → [0,1]s
Attempt 2: upstream call
  ├─ 429/500/timeout → release Backoff::fullJitter(2) → [0,2]s
Attempt 3: upstream call
  ├─ 429/500/timeout → release Backoff::fullJitter(3) → [0,4]s
Attempt 4: upstream call
  ├─ 429/500/timeout → release Backoff::fullJitter(4) → [0,8]s
Attempt 5: upstream call
  ├─ 429/500/timeout → release Backoff::fullJitter(5) → [0,8]s (capped)
Attempt 6: upstream call
  ├─ 429/500/timeout → release Backoff::fullJitter(6) → [0,8]s (capped)
Attempt 7: (final try)
  └─ Any error → job fails, failed() called
```

---

## Timeout Chain

```
HTTP connect timeout:  3s   (config: refresh.http_connect_timeout)
HTTP read timeout:    10s   (config: refresh.http_timeout)
Job timeout:          30s   (config: refresh.job_timeout)
Horizon stop_grace:   45s   (compose.yaml)
Queue retry_after:    90s   (config: queue.connections.redis.retry_after)
Job retryUntil:      10min  (RefreshProfile::retryUntil)
```

**Critical constraint:** `job_timeout < retry_after` (30s < 90s). If a job exceeds 30s, Horizon kills it before the queue considers it stuck. The 90s `retry_after` gives Horizon time to detect the timeout and re-release the job.

---

## Revision Check Mechanism

The revision check prevents stale data from overwriting fresh data in concurrent refresh scenarios.

```php
// ProfileWriter::applySuccess()
DB::table('profiles')
    ->where('id', $profile->id)
    ->where(function ($query) use ($payload) {
        $query->whereNull('revision')           // first fetch
            ->orWhere('revision', '<', $payload->revision);  // newer data
    })
    ->update([...]);
```

**Outcome determination:**

| DB rows updated | Meaning                              | outcome            |
| --------------- | ------------------------------------ | ------------------ |
| 1               | Revision was newer → data applied    | `success`          |
| 0               | Revision was equal or older → skipped | `stale_revision`   |

**Crash replay scenario:**

1. Job fetches upstream → revision=11 (profile has revision=10).
2. Job applies success → profile updated to revision=11.
3. Worker crashes before acknowledging the job.
4. Queue retries the same job after `retry_after`.
5. Job fetches upstream → revision=11 again.
6. Revision check: `11 < 11` is false → `stale_revision`.
7. Profile data is not double-written. Audit trail shows both attempts.

---

## Account Isolation

### Cooldown

When an account hits a rate limit (429), all jobs for that account are delayed.

```
Redis key:  refresh:cooldown:account:{account_id}
Value:      Unix timestamp (cooldown expiry)
TTL:        60 seconds
```

**Cooldown duration:** `min(2 * 2^attempt, 30)` seconds + 0-2s jitter.

| Attempt | Base Delay | Cap  |
| ------- | ---------- | ---- |
| 1       | 2s         | 2s   |
| 2       | 4s         | 4s   |
| 3       | 8s         | 8s   |
| 4       | 16s        | 16s  |
| 5       | 30s        | 30s  |

**Cooldown cleared** on successful refresh.

### Concurrency

Limits how many jobs from the same account run simultaneously.

```
Redis key:  refresh:concurrency:account:{account_id}
Value:      Current running job count (integer)
TTL:        60 seconds
Limit:      account.max_concurrency (default 2)
```

When count >= limit, the job is released with a 2-5s random delay. The counter is incremented before execution and decremented in a `finally` block to prevent leaks on exceptions.

---

## Redis Key Patterns

| Pattern                              | Purpose                          | TTL    |
| ------------------------------------ | -------------------------------- | ------ |
| `refresh:cooldown:account:{id}`      | Account rate-limit cooldown      | 60s    |
| `refresh:concurrency:account:{id}`   | Active job count per account     | 60s    |
| `refresh:upstream_attempts:{uuid}`   | Per-job upstream attempt counter | 86400s |
| `fake:scenario:{username}`           | Fake upstream scenario config    | none   |
| `fake:request_count:{username}`      | Fake upstream request counter    | none   |
| `onlyfans:signing_rules`             | Cached request signing rules     | 3600s  |
| `crash:flag:{profile_id}`            | Crash replay test flag           | none   |
| `workload:unblock:account_id`        | Workload unblock target          | none   |
| `workload:unblock:seed`              | Workload seed for scenarios      | none   |
| `workload:unblock:at`                | Workload unblock timestamp       | none   |
| `queues:refresh`                     | Horizon queue length (auto)      | —      |

---

## Service Communication Diagram

```
┌──────────────────────────────────────────────────────────────────────┐
│                                                                      │
│  Browser ──▶ app (nginx:80) ──▶ php (fpm:9000)                     │
│                                    │                                 │
│                                    ├─ ProfileController              │
│                                    │   └─ RefreshProfile::dispatch() │
│                                    │                                 │
│                                    └─ writes to ──▶ postgres         │
│                                                      │               │
│  scheduler ──▶ php artisan schedule:work              │               │
│      │                                                │               │
│      └─ profiles:schedule-refreshes ──▶ dispatch jobs │               │
│                                                                  │   │
│  horizon ──▶ php artisan horizon                                  │   │
│      │                                                            │   │
│      └─ Worker picks up RefreshProfile job                        │   │
│           │                                                       │   │
│           ├─ Middleware checks ──▶ redis                           │   │
│           │                                                       │   │
│           ├─ FakeUpstreamClient::fetch()                          │   │
│           │   └─ HTTP GET ──▶ upstream (fake API:8081)            │   │
│           │       └─ reads ──▶ redis (fake:scenario:*)            │   │
│           │                                                       │   │
│           ├─ ProfilePayload::fromJson()                           │   │
│           ├─ ProfileWriter::applySuccess() ──▶ postgres           │   │
│           └─ ProfileWriter::logAttempt() ──▶ postgres             │   │
│                                                                      │
└──────────────────────────────────────────────────────────────────────┘
```

---

## Scaling Considerations

### Horizontal Scaling

- **Horizon workers:** Add more `horizon` containers. Horizon distributes jobs across all workers via Redis. Each worker independently enforces cooldown and concurrency per account.
- **Scheduler:** The `withoutOverlapping()->onOneServer()` constraint ensures only one scheduler dispatches per minute, even with multiple scheduler containers. Use Horizon's Redis lock for leader election.
- **Upstream (fake):** The fake API uses `PHP_CLI_SERVER_WORKERS=16` for concurrency. In production, replace with a dedicated service.

### Vertical Scaling

- **Worker concurrency:** Increase Horizon's `maxProcesses` to process more jobs concurrently. Must stay within `max_concurrency` limits per account.
- **Database:** The `refresh_attempts` table grows unboundedly. Partition by `created_at` or archive old rows.

### Resource Constraints

| Resource              | Bottleneck                      | Mitigation                    |
| --------------------- | ------------------------------- | ----------------------------- |
| Redis connections     | Each worker uses 1+ connections | Monitor with `redis-cli info` |
| PostgreSQL connections| Each worker uses 1 per job      | Use connection pooling (PgBouncer) |
| Upstream rate limits  | 429 responses                   | Cooldown + backoff handle this |
| Disk (refresh_attempts)| Grows per attempt              | Archive/rotate periodically   |

### Queue Tuning

```php
// config/queue.php — redis connection
'redis' => [
    'driver' => 'redis',
    'retry_after' => 90,  // Must be > job_timeout (30s)
],
```

```php
// config/horizon.php (not shown, but key settings):
'maxProcesses' => 10,        // Workers per supervisor
'balance' => 'auto',         // Auto-scale between queues
'minProcesses' => [
    'refresh' => 2,          // Minimum refresh workers
],
```
