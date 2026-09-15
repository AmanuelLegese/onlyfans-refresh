<?php

namespace App\Upstream;

use App\Refresh\Exceptions\ServerError;
use App\Refresh\Exceptions\UpstreamTimeout;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Signs OnlyFans API requests with the community-maintained dynamic rules.
 *
 * sign = format(sha1("{static_param}\n{time_ms}\n{path}\n{user_id}"), hex(checksum)), where
 * checksum = sum of the ASCII codes of the hash characters at checksum_indexes + checksum_constant.
 */
class RequestSigner
{
    private const RULES_CACHE_KEY = 'onlyfans:dynamic_rules';

    /**
     * @return array{app-token: string, sign: string, time: string, user-id: string}
     */
    public function headersFor(string $path, string $userId = '0'): array
    {
        $rules = $this->rules();
        $time = (string) (int) floor(microtime(true) * 1000);

        return [
            'app-token' => $rules['app_token'],
            'sign' => self::sign($rules, $path, $time, $userId),
            'time' => $time,
            'user-id' => $userId,
        ];
    }

    /**
     * @param  array{static_param: string, format: string, checksum_indexes: list<int>, checksum_constant: int}  $rules
     */
    public static function sign(array $rules, string $path, string $time, string $userId): string
    {
        $hash = sha1(implode("\n", [$rules['static_param'], $time, $path, $userId]));

        $checksum = $rules['checksum_constant'];
        foreach ($rules['checksum_indexes'] as $index) {
            $checksum += ord($hash[$index]);
        }

        [$prefix, $suffix] = explode('{}', $rules['format'], 2);

        return $prefix.$hash.str_replace('{:x}', dechex(abs($checksum)), $suffix);
    }

    /** Drop cached rules, e.g. after OnlyFans rejects a signature. */
    public function forgetRules(): void
    {
        Cache::forget(self::RULES_CACHE_KEY);
    }

    /**
     * @return array{static_param: string, format: string, checksum_indexes: list<int>, checksum_constant: int, app_token: string}
     */
    private function rules(): array
    {
        return Cache::remember(self::RULES_CACHE_KEY, config('refresh.onlyfans_rules_ttl', 300), function (): array {
            try {
                $response = Http::timeout(config('refresh.http_timeout', 10))
                    ->connectTimeout(config('refresh.http_connect_timeout', 3))
                    ->get(config('refresh.onlyfans_rules_url'));
            } catch (ConnectionException $e) {
                throw new UpstreamTimeout($e);
            }

            $rules = $response->json();

            if (! $response->successful() || ! $this->isValid($rules)) {
                // Retryable: without valid rules no request can be signed.
                throw new ServerError($response->successful() ? 502 : $response->status());
            }

            return $rules;
        });
    }

    private function isValid(mixed $rules): bool
    {
        return is_array($rules)
            && is_string($rules['static_param'] ?? null)
            && is_string($rules['format'] ?? null) && str_contains($rules['format'], '{}') && str_contains($rules['format'], '{:x}')
            && is_array($rules['checksum_indexes'] ?? null)
            && is_int($rules['checksum_constant'] ?? null)
            && is_string($rules['app_token'] ?? null);
    }
}
