<?php

namespace App\Upstream;

use App\Models\Account;
use App\Refresh\Exceptions\{ClientError, MalformedResponse, RateLimited, ServerError, UpstreamTimeout};
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class FakeUpstreamClient implements ProfileSource
{
    public function fetch(Account $account, string $username): array
    {
        $baseUrl = config('refresh.upstream_url', 'http://upstream:8081');
        $url = "{$baseUrl}/fake/api/users/{$username}";

        $token = $account->credentials['token'] ?? 'test-token';

        try {
            $response = Http::timeout(config('refresh.http_timeout', 10))
                ->connectTimeout(config('refresh.http_connect_timeout', 3))
                ->withHeaders([
                    'app-token' => $token,
                    'x-request-id' => Str::uuid()->toString(),
                ])
                ->get($url);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            throw new UpstreamTimeout($e);
        }

        $status = $response->status();

        if ($status === 429) {
            $retryAfter = (int) $response->header('Retry-After', 0);
            throw new RateLimited($retryAfter);
        }

        if ($status >= 500) {
            throw new ServerError($status);
        }

        if ($status >= 400) {
            throw new ClientError($status);
        }

        $json = $response->json();

        if (!is_array($json) || empty($json)) {
            throw new MalformedResponse('Empty or non-array response');
        }

        return $json;
    }
}
