<?php

namespace App\Upstream;

use Illuminate\Support\Facades\{Http, Redis};

class RequestSigner
{
    private const RULES_URL_KEY = 'onlyfans:signing_rules';
    private const RULES_CACHE_TTL = 3600;

    public function sign(array $credentials, string $endpoint, string $method = 'GET'): array
    {
        $rules = $this->getRules();
        $time = (string) time();

        $sign = $this->computeSign(
            rules: $rules,
            method: $method,
            endpoint: $endpoint,
            time: $time,
            cookie: $credentials['cookie'] ?? '',
            xBc: $credentials['x-bc'] ?? '',
        );

        return [
            'sign' => $sign,
            'time' => $time,
        ];
    }

    private function getRules(): array
    {
        $cached = Redis::get(self::RULES_URL_KEY);
        if ($cached) {
            return json_decode($cached, true);
        }

        $rulesUrl = config('refresh.onlyfans_rules_url');
        if (!$rulesUrl) {
            return $this->getDefaultRules();
        }

        try {
            $response = Http::timeout(5)->get($rulesUrl);
            if ($response->successful()) {
                $rules = $response->json();
                Redis::setex(self::RULES_URL_KEY, self::RULES_CACHE_TTL, json_encode($rules));
                return $rules;
            }
        } catch (\Throwable) {
            // Fall back to default rules
        }

        return $this->getDefaultRules();
    }

    private function computeSign(array $rules, string $method, string $endpoint, string $time, string $cookie, string $xBc): string
    {
        $secret = $rules['secret'] ?? '';

        $token = $this->extractToken($cookie);

        $payload = strtoupper($method) . "\n{$endpoint}\n{$time}\n{$xBc}\n{$token}";

        return hash_hmac('sha1', $payload, $secret);
    }

    private function extractToken(string $cookie): string
    {
        if (preg_match('/sess=([a-zA-Z0-9]+)/', $cookie, $matches)) {
            return $matches[1];
        }

        return '';
    }

    private function getDefaultRules(): array
    {
        return [
            'secret' => '',
            'format' => 'default',
        ];
    }
}
