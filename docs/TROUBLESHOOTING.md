# Troubleshooting

## Container Won't Start

### Symptoms

- `docker compose up` exits immediately
- `docker compose ps` shows services in "Exit" or "Restarting" state
- Error: `bind: address already in use`

### Cause

Another process is using ports 80, 5432, 6379, or 8080.

### Fix

```bash
# Find what's using the port
sudo lsof -i :80
sudo lsof -i :5432
sudo lsof -i :6379

# Kill the conflicting process or change ports in compose.yaml
```

### Symptoms

- Error: `permission denied` on volume mount

### Cause

File ownership mismatch between host and container.

### Fix

```bash
# Rebuild with your UID/GID
UID=$(id -u) GID=$(id -g) make build
make up
```

### Symptoms

- Error: `no space left on device`

### Cause

Docker disk usage exceeded.

### Fix

```bash
# Check Docker disk usage
docker system df

# Remove unused images, containers, volumes
docker system prune -af --volumes
```

## Tests Failing

### Symptoms

- `make test` exits with errors
- Pest reports failing tests

### Cause

1. Outdated test database
2. Missing environment variables
3. Code change introduced a regression

### Fix

```bash
# Reset database and run tests
make migrate
make test

# Run with verbose output
docker compose run --rm artisan test --verbose

# Run a specific test file
docker compose run --rm artisan test tests/Unit/ProfilePayloadTest.php

# Run tests matching a filter
docker compose run --rm artisan test --filter="parses new format"
```

### Symptoms

- Error: `SQLSTATE[HY000] Connection refused`

### Cause

Postgres container is not running or not healthy.

### Fix

```bash
# Check Postgres health
docker compose ps postgres

# View Postgres logs
docker compose logs postgres

# Restart Postgres
docker compose restart postgres

# Wait for health check to pass
docker compose up -d postgres
```

## Horizon Not Processing Jobs

### Symptoms

- Jobs are dispatched but never complete
- Horizon dashboard shows no workers
- `horizon:status` returns "paused" or "inactive"

### Cause

1. Horizon worker crashed
2. Horizon is paused
3. Redis connection issue
4. Worker memory exceeded limit

### Fix

```bash
# Check Horizon status
docker compose run --rm artisan horizon:status

# Terminate and restart workers
docker compose run --rm artisan horizon:terminate

# Check for failed jobs
docker compose run --rm artisan queue:failed

# Retry failed jobs
docker compose run --rm artisan queue:retry all

# View Horizon logs
docker compose logs horizon
```

### Symptoms

- Horizon shows "Connection Refused" errors

### Cause

Redis is down or unreachable.

### Fix

```bash
# Check Redis status
docker compose ps redis

# Restart Redis
docker compose restart redis

# Test Redis connectivity
docker compose exec redis redis-cli ping
# Should return: PONG
```

## Redis Connection Refused

### Symptoms

- Error: `Redis::connect(): Connection refused`
- Queue jobs fail immediately
- Cache misses increase

### Cause

1. Redis container not running
2. Redis port bound to localhost only
3. Redis out of memory
4. Redis requires authentication

### Fix

```bash
# Check Redis container status
docker compose ps redis

# View Redis logs
docker compose logs redis

# Check Redis memory
docker compose exec redis redis-cli INFO memory

# Check maxmemory policy
docker compose exec redis redis-cli CONFIG GET maxmemory-policy

# Restart Redis
docker compose restart redis

# Flush Redis (if needed, WARNING: clears all data)
docker compose exec redis redis-cli FLUSHALL
```

### Symptoms

- Error: `NOAUTH Authentication required`

### Cause

Redis is configured with a password but none was provided.

### Fix

Add to your `.env`:

```
REDIS_PASSWORD=your-redis-password
```

Restart the affected containers:

```bash
make down
make up
```

## Postgres Connection Refused

### Symptoms

- Error: `SQLSTATE[HY000] Connection refused`
- Migrations fail
- Application returns database errors

### Cause

1. Postgres container not running
2. Postgres not yet accepting connections (still starting)
3. Wrong credentials
4. Port conflict on host

### Fix

```bash
# Check Postgres status
docker compose ps postgres

# Check if Postgres is accepting connections
docker compose exec postgres pg_isready -U onlyfans -d onlyfans

# View Postgres logs
docker compose logs postgres

# Verify credentials
docker compose exec postgres psql -U onlyfans -d onlyfans -c "SELECT 1;"

# If port 5432 is occupied on host
sudo lsof -i :5432
# Kill the conflicting process or change host port in compose.yaml
```

### Symptoms

- Error: `database "onlyfans" does not exist`

### Cause

Postgres init script didn't run.

### Fix

```bash
# The init.sql runs only on first start. Recreate the volume:
docker compose down -v postgres
docker compose up -d postgres

# Wait for health check
docker compose exec postgres pg_isready -U onlyfans -d onlyfans
```

## Fake Upstream Returning 404

### Symptoms

- `FakeUpstreamController` returns `{"error": "Fake upstream disabled"}`
- Profile refresh jobs get `ClientError` exceptions

### Cause

`FAKE_UPSTREAM_ENABLED` is not set to `"true"`.

### Fix

Verify the upstream container environment:

```bash
# Check the running environment
docker compose exec upstream env | grep FAKE_UPSTREAM_ENABLED

# If not set, add to compose.yaml or override:
FAKE_UPSTREAM_ENABLED: "true"

# Restart the upstream container
docker compose restart upstream

# Test the endpoint
curl http://localhost:8081/api2/profiles/madison420ivy
```

### Symptoms

- Upstream returns empty JSON or unexpected format

### Cause

Scenario stored in Redis has invalid configuration.

### Fix

```bash
# Connect to Redis
make redis

# Check the scenario for a username
> GET fake:scenario:madison420ivy

# Delete the scenario to reset to defaults
> DEL fake:scenario:madison420ivy
```

## Queue Jobs Stuck

### Symptoms

- Jobs remain in "pending" or "reserved" status indefinitely
- Queue depth keeps growing
- `queue:table` shows old `reserved_at` timestamps

### Cause

1. Worker process crashed mid-job
2. Job exceeds timeout but wasn't released
3. Redis connection lost during reservation
4. `retry_after` too short

### Fix

```bash
# Check queue depth
docker compose exec redis redis-cli LLEN queues:default

# Check reserved jobs (stuck)
docker compose exec redis redis-cli LRANGE queues:default 0 -1

# Release stuck jobs manually
docker compose run --rm artisan queue:restart

# Retry failed jobs
docker compose run --rm artisan queue:retry all

# Clear all failed jobs (use with caution)
docker compose run --rm artisan queue:flush
```

### Symptoms

- Jobs fail with `MaxAttemptsExceededException`

### Cause

Job exceeded maximum retry attempts (default: 3).

### Fix

```bash
# Check failed jobs
docker compose run --rm artisan queue:failed

# Retry specific job by ID
docker compose run --rm artisan queue:retry <job-id>

# Retry all failed jobs
docker compose run --rm artisan queue:retry all

# Increase retry count in the job class if needed
```

## Profile Likes Showing 0

### Symptoms

- Profile record shows `likes = 0` even though the API returns likes
- `ProfilePayload` parses likes as 0

### Cause

1. Upstream returns the new format (`profile.likes`) but code reads the wrong field
2. The `ProfilePayload::fromJson()` parser isn't handling the format correctly
3. The upstream scenario is configured with `likes: 0`

### Fix

```bash
# Inspect what the upstream is returning
curl -s http://localhost:8081/api2/profiles/madison420ivy | jq .

# Check the scenario in Redis
make redis
> GET fake:scenario:madison420ivy

# Reset the scenario
> DEL fake:scenario:madison420ivy

# Re-run the refresh
make art ARGS="profile:refresh madison420ivy"

# Check the profile record
make art ARGS="tinker --execute=\"echo App\Models\Profile::where('username','madison420ivy')->first()->likes;\""
```

### Symptoms

- Likes are 0 after a real API call (not fake upstream)

### Cause

Real OnlyFans API returned the new format, but `RealOnlyFansClient` or `ResponseMapper` isn't mapping `profile.likes` correctly.

### Fix

1. Check the raw API response in logs
2. Verify `ResponseMapper` handles both formats
3. Check `ProfilePayload::fromJson()` dual-format parser

```bash
# Check application logs for the response
docker compose logs php | grep "upstream_response"

# Inspect the ResponseMapper
cat src/app/Upstream/ResponseMapper.php
```

## Stale Revision Errors

### Symptoms

- Jobs complete with `stale_revision` status
- Profile data is not updated
- `RefreshAttempt` shows `stale_revision` in the result column

### Cause

1. Another job wrote a newer revision for the same profile
2. Out-of-order delivery: an older revision arrived after a newer one
3. Duplicate job execution: same profile refreshed twice concurrently

This is **expected behavior** — the system correctly protects against stale writes.

### Fix

```bash
# Check revision history
docker compose run --rm artisan tinker --execute="
  App\Models\RefreshAttempt::where('profile_id', 1)
    ->latest()
    ->take(10)
    ->get()
    ->each(fn(\$a) => print_r(\$a->only('id','revision','result','created_at')))
"

# Verify the current profile revision
docker compose run --rm artisan tinker --execute="
  App\Models\Profile::where('username','madison420ivy')
    ->first()
    ->only('username','revision','likes')
"

# If this happens frequently, check for race conditions in job dispatch
docker compose run --rm artisan tinker --execute="
  App\Models\RefreshAttempt::where('result', 'stale_revision')->count()
"
```

### Symptoms

- Stale revision errors on every attempt

### Cause

The revision is stuck at a static value and never increments.

### Fix

Check the upstream scenario:

```bash
make redis
> GET fake:scenario:madison420ivy

# If revision_mode is 'static', the revision never changes
# Set revision_mode to 'time' for dynamic revisions
> SET fake:scenario:madison420ivy '{"format":"new","revision_mode":"time","revision":100}'
```

## Memory Issues

### Symptoms

- Horizon worker killed by OOM (Out of Memory)
- Docker container restarts unexpectedly
- `docker stats` shows memory climbing

### Cause

1. Job processing leak (accumulated state across jobs)
2. Large payload in queue
3. Horizon worker memory limit too low
4. Memory leak in application code

### Fix

```bash
# Check container memory usage
docker stats --no-stream

# Check Horizon worker memory config
docker compose run --rm artisan tinker --execute="
  config('horizon.environments.production')
"

# Increase memory limit in config/horizon.php
'memory' => 1024  # MB

# Check for memory-intensive operations
docker compose logs horizon | grep -i memory

# Monitor a single worker's memory
docker compose exec horizon sh -c "ps aux | grep php"
```

### Symptoms

- Error: `Allowed memory size of X bytes exhausted`

### Cause

A single operation exceeds PHP's memory limit.

### Fix

```bash
# Increase PHP memory limit in dockerfiles/php.dockerfile
# Add: php_value[memory_limit] = 512M

# Or set in .env
PHP_INI_MEMORY_LIMIT=512M

# Restart PHP containers
docker compose restart php horizon
```

### Symptoms

- Redis out of memory errors

### Cause

Redis `maxmemory` reached, keys being evicted.

### Fix

```bash
# Check Redis memory
docker compose exec redis redis-cli INFO memory

# Increase maxmemory in compose.yaml
command:
  - redis-server
  - --appendonly
  - "yes"
  - --maxmemory
  - "512mb"

# Or clear old queue data
docker compose exec redis redis-cli DEL queues:default
```
