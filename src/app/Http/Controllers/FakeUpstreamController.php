<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Redis;

/**
 * Fake OnlyFans profile API driven by a per-username scenario stored in Redis.
 *
 * Random choices are derived from crc32(seed|username|request number), so the same
 * scenario and request sequence always produce the same responses.
 */
class FakeUpstreamController extends Controller
{
    public function __invoke(Request $request, string $username): JsonResponse|Response
    {
        if (! config('refresh.upstream_enabled', false)) {
            return response()->json(['error' => 'Fake upstream disabled'], 404);
        }

        $scenario = $this->getScenario($username);
        $requestNumber = (int) Redis::incr("fake:request_count:{$username}");
        $roll = fn (string $salt): int => crc32("{$scenario['seed']}|{$username}|{$requestNumber}|{$salt}") % 100;

        usleep(((int) ($scenario['latency_ms'] ?? 0)) * 1000);

        if ($roll('slow') < ($scenario['p_slow'] ?? 0)) {
            usleep(((int) ($scenario['slow_ms'] ?? 0)) * 1000);
        }

        if (now()->timestamp < ($scenario['rate_limit_until'] ?? 0) || $roll('429') < ($scenario['p429'] ?? 0)) {
            $response = response()->json(['error' => 'Too Many Requests'], 429);

            // Only send Retry-After when the scenario asks for it; the incident's 429s have none.
            if (($scenario['retry_after'] ?? 0) > 0) {
                $response->header('Retry-After', (string) $scenario['retry_after']);
            }

            return $response;
        }

        if ($roll('500') < ($scenario['p500_empty'] ?? 0)) {
            return response('', 500);
        }

        $revision = $this->computeRevision($scenario);

        return response()->json($this->buildResponse($scenario, $username, $this->computeLikes($scenario, $revision), $revision));
    }

    /**
     * @return array<string, mixed>
     */
    private function getScenario(string $username): array
    {
        $stored = Redis::get("fake:scenario:{$username}");
        $scenario = $stored ? json_decode($stored, true) : null;

        return is_array($scenario) ? $scenario + $this->defaultScenario() : $this->defaultScenario();
    }

    /**
     * @return array<string, mixed>
     */
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
            'seed' => 'default',
            'revision_mode' => 'static',
        ];
    }

    /**
     * @param  array<string, mixed>  $scenario
     */
    private function computeRevision(array $scenario): int
    {
        if (($scenario['revision_mode'] ?? 'static') === 'time') {
            $base = $scenario['base_revision'] ?? 1;
            $elapsed = now()->timestamp - ($scenario['started_at'] ?? now()->timestamp);

            return $base + intdiv($elapsed, 5);
        }

        return $scenario['revision'] ?? 11;
    }

    /**
     * @param  array<string, mixed>  $scenario
     */
    private function computeLikes(array $scenario, int $revision): int
    {
        if (($scenario['revision_mode'] ?? 'static') === 'time') {
            return 120000 + $revision;
        }

        return $scenario['likes'] ?? 121000;
    }

    /**
     * @param  array<string, mixed>  $scenario
     * @return array<string, mixed>
     */
    private function buildResponse(array $scenario, string $username, int $likes, int $revision): array
    {
        $base = [
            'username' => $username,
            'revision' => $revision,
            'name' => $username,
            'avatar_url' => "https://example.com/{$username}.jpg",
            'posts_count' => 100,
            'photos_count' => 50,
            'videos_count' => 10,
        ];

        return ($scenario['format'] ?? 'new') === 'old'
            ? $base + ['likes' => $likes]
            : $base + ['profile' => ['likes' => $likes]];
    }
}
