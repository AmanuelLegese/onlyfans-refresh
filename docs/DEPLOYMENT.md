# Production Deployment Guide

## Environment Variables

Create a `.env.production` file or set these in your deployment environment:

### Application

| Variable           | Example                 | Description |
|--------------------|-------------------------|-------------|
| `APP_NAME`         | `OnlyFansProfileRefresh` | Application name |
| `APP_ENV`          | `production`            | Environment identifier |
| `APP_KEY`          | `base64:...`            | Encryption key (generate with `php artisan key:generate`) |
| `APP_DEBUG`        | `false`                 | Disable verbose error pages |
| `APP_URL`          | `https://profiles.example.com` | Public URL |
| `APP_MAINTENANCE_DRIVER` | `file`            | Maintenance mode storage driver |

### Database (PostgreSQL)

| Variable           | Example                    | Description |
|--------------------|----------------------------|-------------|
| `DB_CONNECTION`    | `pgsql`                    | Database driver |
| `DB_HOST`          | `db.internal`              | Database host |
| `DB_PORT`          | `5432`                     | Database port |
| `DB_DATABASE`      | `onlyfans`                 | Database name |
| `DB_USERNAME`      | `onlyfans`                 | Database user |
| `DB_PASSWORD`      | `your-secure-password`     | Database password (never commit) |
| `DB_SSLMODE`       | `require`                  | SSL mode for connections |
| `DB_PREFIX`        | (empty)                    | Table prefix |

### Redis

| Variable           | Example                    | Description |
|--------------------|----------------------------|-------------|
| `REDIS_CLIENT`     | `phpredis`                 | PHP Redis extension |
| `REDIS_HOST`       | `redis.internal`           | Redis host |
| `REDIS_PORT`       | `6379`                     | Redis port |
| `REDIS_PASSWORD`   | `your-redis-password`      | Redis auth password |
| `REDIS_PREFIX`     | `onlyfans-`                | Key prefix for isolation |

### Queue

| Variable           | Example         | Description |
|--------------------|-----------------|-------------|
| `QUEUE_CONNECTION` | `redis`         | Queue backend (use `redis` in production) |

### Horizon

| Variable           | Example         | Description |
|--------------------|-----------------|-------------|
| `HORIZON_PREFIX`   | `horizon:`      | Redis key prefix for Horizon |

### Upstream (OnlyFans API)

| Variable           | Example         | Description |
|--------------------|-----------------|-------------|
| `FAKE_UPSTREAM_ENABLED` | `false`  | Disable fake upstream in production |
| `ONLYFANS_API_BASE` | `https://onlyfans.com` | OnlyFans API base URL |
| `ONLYFANS_API_KEY` | `your-api-key`  | OnlyFans API authentication key |
| `ONLYFANS_API_SECRET` | `your-secret` | OnlyFans API secret for request signing |

### Logging

| Variable           | Example         | Description |
|--------------------|-----------------|-------------|
| `LOG_CHANNEL`      | `stack`         | Log channel(s) |
| `LOG_STACK`        | `daily`         | Comma-separated log channels |
| `LOG_LEVEL`        | `warning`       | Minimum log level (debug, info, warning, error) |
| `LOG_DEPRECATIONS_CHANNEL` | `null`  | Deprecation log channel |

### Session & Cache

| Variable           | Example         | Description |
|--------------------|-----------------|-------------|
| `SESSION_DRIVER`   | `redis`         | Session storage backend |
| `CACHE_STORE`      | `redis`         | Cache storage backend |

## Docker Compose Production Overrides

Create `docker-compose.prod.yml` at the project root:

```yaml
# docker-compose.prod.yml
services:
  app:
    build:
      context: ./dockerfiles
      dockerfile: nginx.dockerfile
      args:
        UID: 1000
        GID: 1000
    ports:
      - "80:80"
    volumes:
      - ./src:/var/www/html:ro
    restart: always
    deploy:
      resources:
        limits:
          memory: 256M

  php:
    build:
      context: ./dockerfiles
      dockerfile: php.dockerfile
      args:
        UID: 1000
        GID: 1000
    restart: always
    deploy:
      resources:
        limits:
          memory: 512M

  horizon:
    command: php artisan horizon
    restart: always
    stop_grace_period: 60s
    deploy:
      resources:
        limits:
          memory: 512M

  scheduler:
    command: php artisan schedule:work
    restart: always

  upstream:
    # Disable in production
    deploy:
      replicas: 0

  postgres:
    restart: always
    deploy:
      resources:
        limits:
          memory: 1G
    volumes:
      - pgdata:/var/lib/postgresql/data
      # Mount a custom postgres.conf for production tuning
      # - ./config/postgres.conf:/etc/postgresql/postgresql.conf:ro

  redis:
    command:
      - redis-server
      - --appendonly
      - "yes"
      - --maxmemory
      - "256mb"
      - --maxmemory-policy
      - "allkeys-lru"
    restart: always
    deploy:
      resources:
        limits:
          memory: 512M
```

Start with:

```bash
docker compose -f compose.yaml -f docker-compose.prod.yml up -d
```

## Database Migration Strategy

### Initial Deployment

```bash
# Run all migrations on first deploy
docker compose run --rm artisan migrate --force
```

### Subsequent Deployments

```bash
# Run pending migrations only (safe, idempotent)
docker compose run --rm artisan migrate --force
```

### Zero-Downtime Migrations

1. **Deploy schema changes** that are backward-compatible first
2. **Deploy code** that uses the new schema
3. **Remove old columns** in a follow-up deployment

```php
// Example: Adding a column without downtime
Schema::table('profiles', function (Blueprint $table) {
    $table->integer('new_field')->default(0);
});

// Later: Remove after all code uses new_field
Schema::table('profiles', function (Blueprint $table) {
    $table->dropColumn('new_field');
});
```

### Rollback a Migration

```bash
# Rollback the last batch
docker compose run --rm artisan migrate:rollback --force

# Rollback everything (DESTRUCTIVE)
docker compose run --rm artisan migrate:fresh --force
```

## Redis Configuration

### Production redis.conf

Create `config/redis.conf`:

```
# Persistence
appendonly yes
appendfsync everysec

# Memory
maxmemory 256mb
maxmemory-policy allkeys-lru

# Security
requirepass your-redis-password
rename-command FLUSHALL ""
rename-command FLUSHDB ""
rename-command DEBUG ""

# Performance
tcp-backlog 511
timeout 300
tcp-keepalive 300
```

### Redis Monitoring

```bash
# Check memory usage
docker compose exec redis redis-cli INFO memory

# Check connected clients
docker compose exec redis redis-cli INFO clients

# Check key count
docker compose exec redis redis-cli DBSIZE

# Monitor real-time commands
docker compose exec redis redis-cli MONITOR
```

## Horizon Configuration and Workers

### config/horizon.php

The Horizon configuration controls worker processes, timeouts, and queues.

```php
<?php

return [
    'environments' => [
        'production' => [
            'supervisor-1' => [
                'connection' => 'redis',
                'queue' => ['default', 'high', 'low'],
                'balance' => 'auto',
                'autoScalingStrategy' => 'time',
                'maxProcesses' => 10,
                'maxTime' => 3600,
                'maxJobs' => 1000,
                'memory' => 512,
                'tries' => 3,
                'timeout' => 60,
                'nice' => 0,
            ],
        ],
    ],
];
```

### Horizon Scaling

```bash
# Check worker status
docker compose run --rm artisan horizon:status

# Terminate and restart workers (graceful)
docker compose run --rm artisan horizon:terminate

# Pause workers (stop picking new jobs)
docker compose run --rm artisan horizon:pause

# Resume workers
docker compose run --rm artisan horizon:continue
```

### Worker Sizing

For a system processing 50M jobs/day:

- **Throughput**: ~579 jobs/second sustained
- **Workers**: 4-8 processes with `maxProcesses: 10`
- **Balance mode**: `auto` (scales based on queue depth)
- **Strategy**: `time` (longer queues get more workers)

## Logging Setup

### Log Channels

Configure in `config/logging.php`:

```php
'channels' => [
    'stack' => [
        'driver' => 'stack',
        'channels' => ['daily', 'slack'],
        'ignore_exceptions' => false,
    ],

    'daily' => [
        'driver' => 'daily',
        'path' => storage_path('logs/laravel.log'),
        'level' => env('LOG_LEVEL', 'warning'),
        'days' => 14,
    ],

    'stderr' => [
        'driver' => 'monolog',
        'level' => env('LOG_LEVEL', 'debug'),
        'handler' => StreamHandler::class,
        'formatter' => env('LOG_STDERR_FORMATTER'),
        'with' => ['stream' => 'php://stderr'],
        'processors' => [Monolog\Processor\PsrLogMessageProcessor::class],
    ],
],
```

### Log Rotation

Logs are rotated by the `daily` channel automatically. For production:

```bash
# Ensure log directory exists and has correct permissions
docker compose exec php mkdir -p storage/logs
docker compose exec php chmod 775 storage/logs
```

### Centralized Logging

For production, ship logs to an external service:

```bash
# Install a log shipper in the container
# Add to your Dockerfile:
RUN apt-get update && apt-get install -y filebeat
```

## Monitoring and Alerts

### Health Checks

The Docker Compose file includes health checks for Postgres and Redis:

```yaml
healthcheck:
  test: ["CMD-SHELL", "pg_isready -U onlyfans -d onlyfans"]
  interval: 5s
  timeout: 3s
  retries: 20
```

### Application Health Endpoint

Create `routes/api.php`:

```php
Route::get('/health', function () {
    $checks = [
        'database' => DB::connection()->getPdo() ? 'ok' : 'fail',
        'redis' => Redis::ping() ? 'ok' : 'fail',
        'queue' => DB::table('jobs')->count() < 10000 ? 'ok' : 'backlog',
    ];

    $healthy = !in_array('fail', $checks);

    return response()->json([
        'status' => $healthy ? 'healthy' : 'degraded',
        'checks' => $checks,
        'timestamp' => now()->toISOString(),
    ], $healthy ? 200 : 503);
});
```

### Key Metrics to Monitor

| Metric | Warning | Critical | Source |
|--------|---------|----------|--------|
| Queue depth | > 1000 | > 5000 | `horizon:status` |
| Failed jobs/hour | > 10 | > 50 | `queue:failed` |
| Horizon workers active | < 2 | 0 | `horizon:status` |
| Redis memory | > 80% | > 95% | `INFO memory` |
| DB connections | > 80% pool | > 95% pool | `pg_stat_activity` |
| Response time p95 | > 2s | > 5s | Application logs |

### LongWaitListener

The `LongWaitListener` in `app/Listeners/LongWaitListener.php` logs alerts when queue wait times exceed thresholds.

## Rollback Procedure

### Code Rollback

```bash
# 1. Stop new deployments
# 2. Terminate Horizon workers gracefully
docker compose run --rm artisan horizon:terminate

# 3. Deploy the previous code version
# (depends on your CI/CD tool)

# 4. Run any pending migrations in reverse
docker compose run --rm artisan migrate:rollback --force

# 5. Restart Horizon
docker compose up -d horizon
```

### Database Rollback

```bash
# Rollback last migration batch
docker compose run --rm artisan migrate:rollback --force

# Check migration status
docker compose run --rm artisan migrate:status
```

### Full Reset (Last Resort)

```bash
# WARNING: Destroys all data
docker compose run --rm artisan migrate:fresh --force
docker compose run --rm artisan db:seed --force
```

## Health Checks

### Docker Compose Health Checks

Postgres:
```
pg_isready -U onlyfans -d onlyfans
```

Redis:
```
redis-cli ping
```

### Application Health Check

```bash
# Quick check
curl -s http://localhost/health | jq .

# Check queue status
docker compose run --rm artisan horizon:status

# Check failed jobs
docker compose run --rm artisan queue:failed
```

### Automated Health Check Script

Create `scripts/health-check.sh`:

```bash
#!/bin/bash
set -e

echo "Checking services..."

# Check database
docker compose exec -T postgres pg_isready -U onlyfans -d onlyfans
echo "✓ Database OK"

# Check Redis
docker compose exec -T redis redis-cli ping
echo "✓ Redis OK"

# Check Horizon
HORIZON_STATUS=$(docker compose run --rm artisan horizon:status 2>&1)
if echo "$HORIZON_STATUS" | grep -q "running"; then
    echo "✓ Horizon OK"
else
    echo "✗ Horizon not running"
    exit 1
fi

# Check queue depth
QUEUE_COUNT=$(docker compose exec -T redis redis-cli LLEN queues:default 2>/dev/null || echo "0")
if [ "$QUEUE_COUNT" -gt 5000 ]; then
    echo "⚠ Queue backlog: $QUEUE_COUNT jobs"
fi

echo "All checks passed."
```

## Backup Strategy

### Database Backup

```bash
# Dump the database
docker compose exec -T postgres pg_dump -U onlyfans onlyfans > backup_$(date +%Y%m%d_%H%M%S).sql

# Restore from backup
cat backup_20260915_120000.sql | docker compose exec -T postgres psql -U onlyfans onlyfans
```

### Automated Backup Script

Create `scripts/backup.sh`:

```bash
#!/bin/bash
set -e

BACKUP_DIR="/backups"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
BACKUP_FILE="${BACKUP_DIR}/onlyfans_${TIMESTAMP}.sql"

mkdir -p "$BACKUP_DIR"

# Database dump
docker compose exec -T postgres pg_dump -U onlyfans onlyfans > "$BACKUP_FILE"

# Compress
gzip "$BACKUP_FILE"

# Retain last 30 days
find "$BACKUP_DIR" -name "*.sql.gz" -mtime +30 -delete

echo "Backup saved: ${BACKUP_FILE}.gz"
```

### Backup Schedule

Add to crontab on the host:

```cron
# Daily backup at 2 AM
0 2 * * * /path/to/only/scripts/backup.sh >> /var/log/only-backup.log 2>&1
```

### Redis Backup

Redis persists via AOF (`appendonly yes`). For additional safety:

```bash
# Trigger a background save
docker compose exec redis redis-cli BGSAVE

# Copy the dump file
docker compose exec redis cp /data/dump.rdb /data/backup/dump.rdb
```
