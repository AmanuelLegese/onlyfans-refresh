# API Reference

## HTTP Endpoints

### `GET /` — Profile Dashboard

Returns a Blade-rendered HTML dashboard listing all profiles with search, pagination, and manual refresh controls.

**Query Parameters:**

| Param | Type   | Description                     |
| ----- | ------ | ------------------------------- |
| `q`   | string | Optional search (username/name) |

**Response:** `200 OK` — HTML page

**Behavior:**
- Profiles are sorted by `next_refresh_at` ascending (most overdue first).
- Paginated at 25 per page.
- Search filters on `username LIKE %q%` OR `name LIKE %q%`.

**Example:**

```
GET /?q=madison
```

---

### `POST /profiles/{profile}/refresh` — Dispatch Refresh Job

Dispatches a `RefreshProfile` job onto the `refresh` queue for the given profile.

**Path Parameters:**

| Param     | Type    | Description        |
| --------- | ------- | ------------------ |
| `profile` | integer | Profile model ID   |

**Request:**

```
POST /profiles/42/refresh
Content-Type: application/x-www-form-urlencoded
X-CSRF-Token: <token>
```

**Response:** `302 Redirect` back to `/` with flash status.

```
Location: /
Set-Cookie: flash_status=Refresh dispatched for @username
```

**Error Responses:**

| Status | Condition               |
| ------ | ----------------------- |
| 404    | Profile not found       |

**Rate Limiting:** None. Dispatches unconditionally (duplicates are guarded by the `RefreshDispatcher` when called via scheduler/CLI).

---

### `GET /fake/api/users/{username}` — Fake Upstream API

Simulates the OnlyFans upstream API for testing and workload runs. Only active when `FAKE_UPSTREAM_ENABLED=true`.

**Path Parameters:**

| Param      | Type   | Description       |
| ---------- | ------ | ----------------- |
| `username` | string | Profile username  |

**Request Headers:**

| Header       | Required | Description          |
| ------------ | -------- | -------------------- |
| `app-token`  | No       | Account token        |
| `x-request-id` | No     | Request tracing UUID |

**Response — Success (200):**

```json
{
  "username": "johndoe",
  "revision": 11,
  "posts_count": 100,
  "photos_count": 50,
  "videos_count": 10,
  "profile": {
    "likes": 121000
  },
  "name": "johndoe",
  "avatar_url": "https://example.com/johndoe.jpg"
}
```

The response format depends on the scenario's `format` field:

- **`format: "new"`** — likes nested under `profile.likes`
- **`format: "old"`** — likes at top level as `likes`

**Response — Disabled (404):**

```json
{
  "error": "Fake upstream disabled"
}
```

**Response — Rate Limited (429):**

```json
{
  "error": "Too Many Requests"
}
```

Header: `Retry-After: <seconds>`

**Response — Server Error (500):**

```json
{}
```

**Scenario Control (via Redis):**

The fake upstream reads per-username scenarios from Redis key `fake:scenario:{username}`:

```json
{
  "format": "new",
  "rate_limit_until": 0,
  "p429": 60,
  "p500_empty": 15,
  "p_slow": 10,
  "slow_ms": 3000,
  "latency_ms": 100,
  "seed": "deterministic-seed",
  "revision_mode": "static",
  "revision": 11,
  "likes": 121000
}
```

| Field            | Type    | Description                                      |
| ---------------- | ------- | ------------------------------------------------ |
| `format`         | string  | `"new"` or `"old"` response shape                |
| `p429`           | int     | Percentage chance of 429 (0-100)                 |
| `p500_empty`     | int     | Percentage chance of empty 500 (0-100)           |
| `rate_limit_until` | int   | Unix timestamp — always 429 before this time     |
| `revision_mode`  | string  | `"static"` (fixed revision) or `"time"` (incr)   |
| `revision`       | int     | Static revision number                           |
| `likes`          | int     | Static likes count                               |

---

## Artisan Commands

### `incident:reproduce`

Runs through predefined test scenarios (old-format, new-format, missing-likes, empty-500) using the legacy or fixed handler. Prints before/after state for each.

```bash
php artisan incident:reproduce --mode=legacy
php artisan incident:reproduce --mode=fixed
```

**Options:**

| Flag    | Values            | Default  | Description                    |
| ------- | ----------------- | -------- | ------------------------------ |
| `--mode` | `legacy`, `fixed` | `legacy` | Handler to test against        |

**Output:** Tables showing profile state before and after each scenario, plus a summary of legacy handler bugs.

**Docker:**

```bash
make reproduce MODE=fixed
```

---

### `workload:run`

Creates two accounts (A: busy/rate-limited, B: healthy), dispatches ~70 refresh jobs, waits for queue drain, then writes a JSON report.

```bash
php artisan workload:run --mode=fixed --seed=42 --timeout=120
```

**Options:**

| Flag       | Values            | Default | Description                       |
| ---------- | ----------------- | ------- | --------------------------------- |
| `--mode`   | `legacy`, `fixed` | `fixed` | Handler mode                      |
| `--seed`   | integer           | `42`    | Random seed for scenario variation |
| `--timeout` | integer          | `120`   | Max seconds to wait for drain     |

**What it does:**

1. Truncates `accounts`, `profiles`, `refresh_attempts` tables.
2. Clears `fake:*` and `refresh:*` Redis keys.
3. Creates Account A (60 profiles, 60% p429, 15% p500) and Account B (10 profiles, healthy).
4. Dispatches all jobs to `refresh` queue plus 10 duplicate dispatches for Account A.
5. After 20s, unblocks Account A (sets all scenarios to healthy).
6. Polls `queues:refresh` in Redis until empty or timeout.
7. Writes report to `storage/app/workload-{mode}-{seed}.json`.

**Report fields:** dispatched, successes, verified_successes, stale_rejections, rate_limited, server_errors, timeouts, malformed, client_errors, attempts_per_verified_success, median/p95 duration, wrong_data_profiles, duplicate_usernames.

**Docker:**

```bash
make workload MODE=fixed SEED=42 TIMEOUT=120
```

---

### `workload:crash-replay`

Simulates a worker crash mid-apply by setting a Redis crash flag, then verifies idempotent replay on retry.

```bash
php artisan workload:crash-replay --username=crash_test_user --timeout=120
```

**Options:**

| Flag         | Type   | Default           | Description                      |
| ------------ | ------ | ----------------- | -------------------------------- |
| `--username` | string | `crash_test_user` | Profile username to test         |
| `--timeout`  | int    | `120`             | Max seconds to wait for 2nd attempt |

**What it does:**

1. Creates account and profile pinned to revision 10.
2. Sets `crash:flag:{profile_id}` in Redis.
3. Sets upstream scenario to return revision 11.
4. Dispatches `RefreshProfile` job.
5. Monitors `refresh_attempts` table for 2+ rows.
6. Verifies: first attempt = `success`, second = `stale_revision`.

**Docker:**

```bash
make crash TIMEOUT=120
```

---

### `profile:refresh`

Manually dispatches a refresh for a single profile by username. Uses `RefreshDispatcher::dispatchIfNotPending()` to avoid duplicate dispatches.

```bash
php artisan profile:refresh {username} --mode=fixed
```

**Arguments:**

| Arg        | Type   | Description       |
| ---------- | ------ | ----------------- |
| `username` | string | Profile username  |

**Options:**

| Flag    | Values            | Default  | Description       |
| ------- | ----------------- | -------- | ----------------- |
| `--mode` | `legacy`, `fixed` | `fixed`  | Handler mode      |

**Output:**

```
Dispatched refresh for johndoe (mode: fixed)
```

or

```
Refresh already pending for johndoe
```

---

### `profiles:schedule-refreshes`

Dispatches refresh jobs for all profiles where `next_refresh_at <= now` and no refresh is currently pending. Called every minute by the task scheduler.

```bash
php artisan profiles:schedule-refreshes
```

**Behavior:**
- Queries profiles with `next_refresh_at <= now` and `refresh_queued_at IS NULL OR refresh_queued_at < now() - 1 hour`.
- Processes in batches of 500 via `lazyById`.
- Uses `RefreshDispatcher::dispatchIfNotPending()` for atomic claiming.

**Scheduler registration** (`routes/console.php`):

```php
Schedule::command('profiles:schedule-refreshes')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();
```

---

## Error Response Summary

| Exception Class    | HTTP Status | Retryable | Behavior                        |
| ------------------ | ----------- | --------- | ------------------------------- |
| `RateLimited`      | 429         | Yes       | Released with backoff + cooldown |
| `UpstreamTimeout`  | 0           | Yes       | Released with backoff           |
| `ServerError`      | 5xx         | Yes       | Released with backoff           |
| `ClientError`      | 4xx         | No        | Job fails permanently           |
| `MalformedResponse`| 0           | No        | Job fails permanently           |

## Queue Configuration

The application uses Laravel Horizon with Redis as the queue driver. The primary queue is `refresh`.

**Key settings** (`config/refresh.php`):

| Setting                | Default | Description                        |
| ---------------------- | ------- | ---------------------------------- |
| `job_timeout`          | 30s     | Max seconds a job can run          |
| `max_upstream_attempts` | 6      | Retry budget per job               |
| `backoff_base`         | 1s      | Exponential backoff base           |
| `backoff_cap`          | 8s      | Max backoff delay                  |
| `http_timeout`         | 10s     | Upstream HTTP timeout              |
| `http_connect_timeout` | 3s      | Upstream connection timeout        |
| `interval_high_likes`  | 24h     | Refresh interval for >100k likes   |
| `interval_low_likes`   | 72h     | Refresh interval for <=100k likes  |
