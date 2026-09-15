<?php

namespace App\Upstream;

use App\Models\Account;
use App\Refresh\Exceptions\ClientError;
use App\Refresh\Exceptions\MalformedResponse;
use App\Refresh\Exceptions\RateLimited;
use App\Refresh\Exceptions\ServerError;
use App\Refresh\Exceptions\SignatureRejected;
use App\Refresh\Exceptions\UpstreamTimeout;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Fetches a public profile from the OnlyFans API with a signed request.
 *
 * Works logged out (user-id 0, no cookie). Optional account credentials: user_id, cookie,
 * user_agent, x_bc. Credentials are sent as headers only and never logged.
 */
class RealOnlyFansClient implements ProfileSource
{
    public function __construct(
        private RequestSigner $signer,
        private ResponseMapper $mapper,
    ) {}

    public function fetch(Account $account, string $username): array
    {
        $path = '/api2/v2/users/'.rawurlencode($username);
        $credentials = $account->credentials ?? [];
        $revision = (int) floor(microtime(true) * 1000);

        $headers = array_filter([
            'accept' => 'application/json, text/plain, */*',
            'user-agent' => $credentials['user_agent'] ?? config('refresh.onlyfans_user_agent'),
            // Stable per account, derived from the app key so it reveals nothing.
            'x-bc' => $credentials['x_bc'] ?? sha1("x-bc|{$account->id}|".config('app.key')),
            'cookie' => $credentials['cookie'] ?? null,
        ]) + $this->signer->headersFor($path, (string) ($credentials['user_id'] ?? '0'));

        try {
            $response = Http::baseUrl(config('refresh.onlyfans_api_url', 'https://onlyfans.com'))
                ->timeout(config('refresh.http_timeout', 10))
                ->connectTimeout(config('refresh.http_connect_timeout', 3))
                ->withHeaders($headers)
                ->get($path);
        } catch (ConnectionException $e) {
            throw new UpstreamTimeout($e);
        }

        $status = $response->status();

        if ($status === 429) {
            throw new RateLimited((int) $response->header('Retry-After'));
        }

        if ($status === 401 || $status === 403) {
            $this->signer->forgetRules();

            throw new SignatureRejected($status);
        }

        if ($status >= 500) {
            throw new ServerError($status);
        }

        if ($status >= 400) {
            throw new ClientError($status);
        }

        $json = $response->json();

        if (! is_array($json) || $json === []) {
            throw new MalformedResponse('Empty or non-array response');
        }

        return $this->mapper->map($json, $revision);
    }
}
