# OnlyFans Profile Refresh Service

Laravel 13 application that fetches OnlyFans profile data through a queue-based refresh pipeline. Built as a take-home test for the FansAPI Engineer position.

## The Bug

OnlyFans moved `likes` from a top-level response field into a `profile{}` wrapper. The legacy handler (`LegacyRefreshProfile`) reads `$json['likes'] ?? 0`, which silently zeroes out like counts for new-format responses. Additionally, the legacy handler never checks HTTP status codes and marks every response as success.

### Impact

- Every profile using the new API format gets `likes = 0`
- Rate limits (429) and server errors (500) are silently ignored
- No retry logic, no backoff, no audit trail

## The Fix

1. **`ProfilePayload::fromJson()`** — Dual-format parser that reads `profile.likes` first, falls back to top-level `likes`. Validates types (int, >= 0 for likes, >= 1 for revision).

2. **`ProfileWriter`** — Atomic revision check via `UPDATE ... WHERE revision IS NULL OR revision < ?`. Prevents out-of-order and duplicate writes.

3. **`RefreshProfile` job** — Typed exception handling (RateLimited, ServerError, UpstreamTimeout, ClientError, MalformedResponse). Middleware pipeline: `RefreshLogContext` → `AccountCooldown` → `AccountConcurrency` → `WithoutOverlapping`.

4. **`ProfileSource` interface** — `FakeUpstreamClient` (testing) and `RealOnlyFansClient` (production) implement the same contract.

### Before/After

| Scenario | Legacy | Fixed |
|----------|--------|-------|
| New format `profile.likes` | `likes = 0` | `likes = 121000` |
| 429 rate limit | Marked success, no retry | Released with backoff, cooldown set |
| 500 empty body | Marked success | Released with backoff |
| Duplicate delivery | Overwrites with same data | `stale_revision`, data preserved |
| Out-of-order delivery | Overwrites with older data | `stale_revision`, newer data preserved |

## Timeout Chain

```
HTTP timeout (10s) < Job timeout (30s) < Horizon supervisor (60s) < Redis retry_after (90s)
```

Lock `expireAfter` is 120s, longer than job timeout. Even if all guards fail, the revision check in `ProfileWriter::applySuccess()` prevents data corruption.

## Setup

```bash
make build up
make art ARGS="migrate:fresh"
make test
```

## Commands

```bash
# Reproduce the bug
make reproduce MODE=legacy

# Run workload
make workload MODE=fixed SEED=42 TIMEOUT=120

# Crash replay test
make crash TIMEOUT=120

# Manual refresh
make art ARGS="profile:refresh madison420ivy"

# Schedule due profiles
make art ARGS="profiles:schedule-refreshes"
```

## Dashboard

Visit `http://localhost/` for the profile refresh dashboard. Visit `http://localhost/horizon` for the queue dashboard.

## Workload

- **Account A (busy):** 60 profiles, first 20s: 60% 429, 15% 500, 10% slow 3s, then all-ok
- **Account B (healthy):** 10 profiles, 100% ok, mixed formats
- Reports saved to `storage/app/workload-{mode}-{seed}.json`

## Crash Replay

The `workload:crash-replay` command simulates a worker crash mid-apply:
1. Pins a profile to static revision
2. Sets a crash flag in Redis
3. Job applies success then crashes
4. Retry picks up the same job UUID
5. Second attempt: `stale_revision`, profile unchanged

**Limitations:** This does not prove safety against container/host loss, Redis failover losing reserved jobs, or a DB commit acknowledged but lost.

## Scaling to 50M Jobs/Day

At 579 jobs/sec average, the first bottleneck is **Postgres connection pool saturation** — each refresh writes 2 rows (profile update + attempt log), and Horizon workers hold connections during the entire job. With 100 workers at 5 concurrent jobs each, you need 500+ persistent connections.

**What to measure next:**
- Peak arrival rate (95th percentile, not just average)
- Job duration p50/p95/p99 per account
- Retry amplification ratio (total attempts / unique profiles)
- Postgres `idle_in_transaction` count and connection pool utilization
- Redis `used_memory` and `connected_clients`
- Upstream rate limit headers and per-account throttling patterns
- Largest accounts by profile count (power-law distribution)

**First change:** Add PgBouncer in transaction mode to pool Postgres connections. This alone lets you scale from ~50 to ~500 workers without running out of DB connections. Evidence: run the workload at 10x speed and collect `pg_stat_activity` — if `idle` connections exceed 80% of `max_connections`, PgBouncer is the bottleneck relief.

**Production plan (first 15 minutes):**
1. Check Horizon dashboard — are jobs processing? Is `wait` time growing?
2. Check `refresh` log channel for `rate_limited` or `server_error` spikes
3. If upstream is degraded: increase cooldowns via `config/refresh.php` without deploy
4. If DB is saturated: add PgBouncer or reduce `maxProcesses` in Horizon config
5. Roll back: revert to `LegacyRefreshProfile` handler by changing `config('refresh.mode')` to `legacy` — no deploy needed
6. Verify recovery: `artisan profiles:schedule-refreshes` + check `next_refresh_at` is advancing

**Canary rollout:** Deploy fix to one worker first (`artisan horizon:pause`, update code, `artisan horizon:continue`). Monitor for 10 minutes. If `stale_revision` count stays at 0, roll to all workers.

## Stack

- **PHP 8.4** / Laravel 13 / Horizon 5 / Scout 11
- **Postgres 17** / Redis 7
- **Pest 5** for testing (65 tests, SQLite in-memory)
- Docker Compose with nginx, php-fpm, horizon, scheduler, fake-upstream, postgres, redis

**Time spent:** ~2 hours (setup, reproduce, fix, test, document, CI)

## Documentation

| Doc | Description |
|-----|-------------|
| [API Reference](docs/API.md) | HTTP endpoints, Artisan commands, error responses, queue config |
| [Architecture](docs/ARCHITECTURE.md) | Component diagram, data flow, database schema, queue architecture, scaling |
| [Development Guide](docs/DEVELOPMENT.md) | Setup, project structure, code conventions, debugging tips |
| [Deployment Guide](docs/DEPLOYMENT.md) | Environment variables, production config, monitoring, backups |
| [Troubleshooting](docs/TROUBLESHOOTING.md) | Common issues and fixes for containers, tests, Horizon, Redis, Postgres |
| [Testing Guide](docs/TESTING.md) | How to run tests, test structure, mocking, writing new tests |
