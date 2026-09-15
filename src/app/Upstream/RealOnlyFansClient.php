<?php

namespace App\Upstream;

use App\Models\Account;
use App\Refresh\Exceptions\{ClientError, MalformedResponse, RateLimited, ServerError, UpstreamTimeout};
use Illuminate\Support\Facades\Http;

class RealOnlyFansClient implements ProfileSource
{
    private RequestSigner $signer;
    private ResponseMapper $mapper;

    public function __construct(RequestSigner $signer, ResponseMapper $mapper)
    {
        $this->signer = $signer;
        $this->mapper = $mapper;
    }

    public function fetch(Account $account, string $username): array
    {
        $baseUrl = config('refresh.onlyfans_api_url', 'https://onlyfans.com');
        $endpoint = "/api2/v2/users/{$username}";
        $url = "{$baseUrl}{$endpoint}";

        $credentials = $account->credentials;
        $signHeaders = $this->signer->sign($credentials, $endpoint);

        $headers = [
            'accept' => 'application/json, text/plain, */*',
            'app-token' => $credentials['app_token'] ?? '',
            'cookie' => $credentials['cookie'] ?? '',
            'user-agent' => $credentials['user_agent'] ?? 'Mozilla/5.0',
            'x-bc' => $credentials['x-bc'] ?? '',
            'sign' => $signHeaders['sign'],
            'time' => $signHeaders['time'],
        ];

        try {
            $response = Http::timeout(config('refresh.http_timeout', 10))
                ->connectTimeout(config('refresh.http_connect_timeout', 3))
                ->withHeaders($headers)
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

        return $this->mapper->map($json);
    }
}
