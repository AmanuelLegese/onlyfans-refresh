# Development Guide

## Prerequisites

- **Docker** (24.0+)
- **Docker Compose** (v2.20+)
- **Make** (GNU Make 4.0+)

Verify your installation:

```bash
docker --version
docker compose version
make --version
```

## First-Time Setup

```bash
# 1. Clone and enter the project
cd /path/to/only

# 2. Build containers (one-time, ~2-3 min)
make build

# 3. Start all services in detached mode
make up

# 4. Run migrations and seed the database
make migrate
make seed

# 5. Run the test suite
make test
```

Services are now running:

| Service     | URL                    | Description              |
|-------------|------------------------|--------------------------|
| App         | http://localhost        | Dashboard + Horizon UI   |
| Horizon     | http://localhost/horizon| Queue dashboard          |
| Adminer     | http://localhost:8080   | Database browser (tools) |
| Fake Upstream | http://localhost:8081 | Mock OnlyFans API        |

## Project Structure

```
only/
├── compose.yaml              # Docker Compose: 7 services + 3 tools
├── Makefile                  # Developer shortcuts
├── dockerfiles/              # Dockerfiles for PHP, Nginx, Postgres init
├── src/                      # Laravel application root
│   ├── app/
│   │   ├── Console/Commands/ # Artisan commands
│   │   ├── Http/Controllers/ # Controllers (ProfileController, FakeUpstreamController)
│   │   ├── Jobs/             # Queue jobs (RefreshProfile, LegacyRefreshProfile)
│   │   │   └── Middleware/   # Job middleware (AccountCooldown, AccountConcurrency)
│   │   ├── Listeners/        # Event listeners (LongWaitListener)
│   │   ├── Logging/          # Custom log processors (RedactSecretsProcessor)
│   │   ├── Models/           # Eloquent models (Account, Profile, RefreshAttempt)
│   │   ├── Providers/        # Service providers
│   │   ├── Refresh/          # Core refresh logic
│   │   │   ├── Exceptions/   # Typed upstream exceptions
│   │   │   ├── ProfilePayload.php
│   │   │   ├── ProfileWriter.php
│   │   │   ├── RefreshDispatcher.php
│   │   │   ├── RefreshPolicy.php
│   │   │   └── Backoff.php
│   │   └── Upstream/         # Upstream client abstraction
│   │       ├── ProfileSource.php      # Interface
│   │       ├── FakeUpstreamClient.php # Test double
│   │       ├── RealOnlyFansClient.php # Production client
│   │       ├── RequestSigner.php
│   │       └── ResponseMapper.php
│   ├── config/               # Laravel config files
│   ├── database/             # Migrations, seeders, factories
│   ├── routes/               # Route definitions
│   ├── storage/              # Logs, cache, compiled views
│   └── tests/                # Pest test suite
│       ├── Feature/          # Integration tests
│       ├── Unit/             # Unit tests
│       └── Pest.php          # Pest base config
└── docs/                     # This documentation
```

### Docker Services

| Service     | Purpose                                      |
|-------------|----------------------------------------------|
| `app`       | Nginx web server (reverse proxy to PHP-FPM)  |
| `php`       | PHP-FPM application server                    |
| `horizon`   | Laravel Horizon queue worker                  |
| `scheduler` | Laravel task scheduler                        |
| `upstream`  | Fake OnlyFans API (testing)                   |
| `postgres`  | PostgreSQL 17 database                        |
| `redis`     | Redis 7.4 (queue + cache + locks)            |

### Tool Services (opt-in with `--profile tools`)

| Service              | Purpose                    |
|----------------------|----------------------------|
| `composer`           | Run Composer commands       |
| `artisan`            | Run Artisan commands        |
| `laravel-installer`  | Laravel installer           |
| `adminer`            | Database web UI             |

## How to Add a New Command

1. Create the command class:

```bash
make art ARGS="make:command MyCommand"
```

2. Implement the command logic in `app/Console/Commands/MyCommand.php`:

```php
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class MyCommand extends Command
{
    protected $signature = 'my:command {--option=default}';
    protected $description = 'Description of what this command does';

    public function handle(): int
    {
        $option = $this->option('option');
        $this->info("Running with option: {$option}");

        return Command::SUCCESS;
    }
}
```

3. Test it:

```bash
make art ARGS="my:command --option=value"
```

## How to Add a New Middleware

### HTTP Middleware (for routes)

1. Create the middleware:

```bash
make art ARGS="make:middleware MyMiddleware"
```

2. Register it in `app/Http/Kernel.php` (or `bootstrap/app.php` in Laravel 13).

### Job Middleware (for queue jobs)

Create the middleware class directly:

```php
<?php

namespace App\Jobs\Middleware;

use Closure;
use Illuminate\Queue\InteractsWithQueue;

class MyJobMiddleware
{
    public function handle(object $job, Closure $next): void
    {
        // Before the job executes
        $this->someCheck($job);

        $next($job);

        // After the job executes (optional)
    }

    private function someCheck(object $job): void
    {
        // Validation logic here
    }
}
```

Apply it to a job class:

```php
class MyJob implements ShouldQueue
{
    public function middleware(): array
    {
        return [new MyJobMiddleware()];
    }
}
```

## How to Add a New Test

Tests use **Pest 5** with Laravel bindings.

### Unit Test

```bash
make art ARGS="make:test --unit MyUnitTest"
```

```php
<?php

use App\Refresh\ProfilePayload;

it('parses new format likes', function () {
    $payload = ProfilePayload::fromJson([
        'profile' => ['likes' => 121000],
        'username' => 'testuser',
        'revision' => 1,
    ]);

    expect($payload->likes)->toBe(121000);
});
```

### Feature Test

```bash
make art ARGS="make:test MyFeatureTest"
```

```php
<?php

use App\Jobs\RefreshProfile;
use App\Models\Account;
use App\Models\Profile;
use Illuminate\Support\Facades\Queue;

it('dispatches refresh job for a profile', function () {
    $account = Account::factory()->create();
    $profile = Profile::factory()->create(['account_id' => $account->id]);

    Queue::fake();

    RefreshProfile::dispatch($profile->id);

    Queue::assertPushed(RefreshProfile::class);
});
```

### Run Tests

```bash
# Full suite
make test

# Single file
docker compose run --rm artisan test tests/Unit/ProfilePayloadTest.php

# Filter by name
docker compose run --rm artisan test --filter="parses new format"
```

## Code Style Conventions

### PSR-12

The project follows PSR-12 coding standard. Enforce it with Laravel Pint:

```bash
make art ARGS="pint --test"     # Check for violations
make art ARGS="pint --fix"      # Auto-fix
```

### Key Conventions

- **Type declarations** on all method parameters and return types
- **Named arguments** over positional for clarity
- **No comments** unless explicitly requested
- **Early returns** over deep nesting
- **Facades** for stateless services (Redis, Queue, Log)
- **Interfaces** for swappable implementations (e.g., `ProfileSource`)

### Pest Style

- Use `it('does X')` for test names (readable)
- Use `expect()` for assertions (fluent)
- Use `beforeEach()` for shared setup
- Group related tests with `describe()`

```php
describe('ProfilePayload', function () {
    beforeEach(function () {
        $this->raw = ['profile' => ['likes' => 500], 'username' => 'test', 'revision' => 1];
    });

    it('parses likes from new format', function () {
        $payload = ProfilePayload::fromJson($this->raw);
        expect($payload->likes)->toBe(500);
    });

    it('defaults likes to zero when missing', function () {
        unset($this->raw['profile']['likes']);
        $payload = ProfilePayload::fromJson($this->raw);
        expect($payload->likes)->toBe(0);
    });
});
```

## Common Development Tasks

### Reset the Database

```bash
make migrate    # Runs migrate:fresh
```

### Seed with Specific Seeder

```bash
make art ARGS="db:seed --class=AccountSeeder"
```

### Run a Manual Profile Refresh

```bash
make art ARGS="profile:refresh madison420ivy"
```

### Run the Workload Simulator

```bash
make workload MODE=fixed SEED=42 TIMEOUT=120
```

### Reproduce the Legacy Bug

```bash
make reproduce MODE=legacy
```

### Tail Logs

```bash
make logs           # All services
make upstream       # Upstream only
```

### Shell into PHP Container

```bash
make shell          # sh shell
```

### Connect to Redis

```bash
make redis          # redis-cli
```

### Restart Horizon

```bash
make horizon        # Sends SIGTERM for graceful restart
```

## Debugging Tips

### Laravel Pail (Live Log Stream)

```bash
docker compose run --rm artisan pail
```

### Tinker (REPL)

```bash
docker compose run --rm artisan tinker
```

### Inspect Redis Keys

```bash
make redis
> KEYS *
> HGETALL horizon:metrics
```

### Check Horizon Status

```bash
docker compose run --rm artisan horizon:status
```

### Debug a Specific Job

```bash
docker compose run --rm artisan tinker
>>> App\Models\Profile::first()->latestRefreshAttempt
>>> App\Jobs\RefreshProfile::dispatch(1)
```

### View Failed Jobs

```bash
docker compose run --rm artisan queue:failed
```

### Retry a Failed Job

```bash
docker compose run --rm artisan queue:retry <id>
```

### Inspect Docker Logs

```bash
docker compose logs php          # PHP-FPM logs
docker compose logs horizon      # Queue worker logs
docker compose logs upstream     # Fake upstream logs
docker compose logs postgres     # Database logs
```

## IDE Configuration

### Recommended: VS Code + Laravel Extension Pack

Install these extensions:

- **Laravel goto view** — Jump to Blade templates
- **Laravel goto route** — Jump to route definitions
- **PHP Intelephense** — Autocomplete and type inference
- **PHP Debug** — Xdebug integration
- **Laravel Pint** — Auto-format on save

### PHPStan (Optional Static Analysis)

```bash
make composer ARGS="require --dev laravel/boost phpstan/phpstan"
```

Add `phpstan.neon` to the project root:

```neon
includes:
    - vendor/larastan/larastan/extension.neon

parameters:
    paths:
        - app
    level: 5
```

### Laravel Boost

The project includes Laravel Boost for AI-assisted development. Install it:

```bash
composer require laravel/boost --dev
php artisan boost:install
```

### Xdebug (Optional)

Enable Xdebug by building the PHP container with the debug extension. Modify `dockerfiles/php.dockerfile`:

```dockerfile
RUN install-php-extensions xdebug
```

Then set in your `.env`:

```env
XDEBUG_MODE=debug
XDEBUG_CLIENT_HOST=host.docker.internal
```

### Database GUI

Use the bundled Adminer tool:

```bash
docker compose --profile tools up -d adminer
```

Open http://localhost:8080 — server: `postgres`, user: `onlyfans`, password: `secret`.
