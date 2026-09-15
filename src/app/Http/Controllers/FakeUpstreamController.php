<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class FakeUpstreamController extends Controller
{
    public function __invoke(Request $request, string $username): JsonResponse
    {
        if (!config('refresh.upstream_enabled', false)) {
            return response()->json(['error' => 'Fake upstream disabled'], 404);
        }

        $scenario = $this->getScenario($username);
        $requestNumber = $this->incrementRequestCount($username);

        if ($this->shouldRateLimit($scenario)) {
            return response()->json(['error' => 'Too Many Requests'], 429)
                ->header('Retry-After', $scenario['retry_after'] ?? 0);
        }

        if ($this->shouldServerError($scenario)) {
            return response()->json([], 500);
        }

        if ($this->shouldReturnEmpty($scenario)) {
            return response()->json([], 200);
        }

        $revision = $this->computeRevision($scenario, $username);
        $likes = $this->computeLikes($scenario, $revision);

        return response()->json($this->buildResponse($scenario, $username, $likes, $revision));
    }

    private function getScenario(string $username): array
    {
        $key = "fake:scenario:{$username}";
        $stored = Redis::get($key);

        if ($stored) {
            return json_decode($stored, true) ?? $this->defaultScenario();
        }

        return $this->defaultScenario();
    }

    private function defaultScenario(): array
    {
        return [
            'format' => 'new',
            'rate_limit_until' => 0,
            'p429' => 0,
            'p500_empty' => 0,
            'p_slow' => 0,
            'slow_ms' => 3000,
            'latency_ms' => 100,
            'seed' => Str::random(16),
            'revision_mode' => 'static',
        ];
    }

    private function incrementRequestCount(string $username): int
    {
        $key = "fake:request_count:{$username}";
        return (int) Redis::incr($key);
    }

    private function shouldRateLimit(array $scenario): bool
    {
        if (now()->timestamp < ($scenario['rate_limit_until'] ?? 0)) {
            return true;
        }

        if (($scenario['p429'] ?? 0) <= 0) {
            return false;
        }

        $hash = crc32($scenario['seed'] . request()->header('x-request-id', ''));
        return ($hash % 100) < $scenario['p429'];
    }

    private function shouldServerError(array $scenario): bool
    {
        if (($scenario['p500_empty'] ?? 0) <= 0) {
            return false;
        }

        $hash = crc32($scenario['seed'] . request()->header('x-request-id', '') . '500');
        return ($hash % 100) < $scenario['p500_empty'];
    }

    private function shouldReturnEmpty(array $scenario): bool
    {
        return false;
    }

    private function computeRevision(array $scenario, string $username): int
    {
        if (($scenario['revision_mode'] ?? 'static') === 'time') {
            $base = $scenario['base_revision'] ?? 1;
            $elapsed = now()->timestamp - ($scenario['started_at'] ?? now()->timestamp);
            return $base + intdiv($elapsed, 5);
        }

        return $scenario['revision'] ?? 11;
    }

    private function computeLikes(array $scenario, int $revision): int
    {
        if (($scenario['revision_mode'] ?? 'static') === 'time') {
            return 120000 + $revision;
        }

        return $scenario['likes'] ?? 121000;
    }

    private function buildResponse(array $scenario, string $username, int $likes, int $revision): array
    {
        $format = $scenario['format'] ?? 'new';

        $base = [
            'username' => $username,
            'revision' => $revision,
            'posts_count' => 100,
            'photos_count' => 50,
            'videos_count' => 10,
        ];

        if ($format === 'old') {
            return array_merge($base, [
                'likes' => $likes,
                'name' => $username,
                'avatar_url' => "https://example.com/{$username}.jpg",
            ]);
        }

        return array_merge($base, [
            'profile' => ['likes' => $likes],
            'name' => $username,
            'avatar_url' => "https://example.com/{$username}.jpg",
        ]);
    }
}
