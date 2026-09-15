<?php

namespace App\Logging;

class RedactSecretsProcessor
{
    private const SENSITIVE_KEYS = [
        'token', 'password', 'secret', 'credential', 'authorization',
        'cookie', 'api_key', 'apikey', 'access_token', 'refresh_token',
        'client_secret', 'app_secret',
    ];

    public function __invoke(array $record): array
    {
        if (isset($record['extra'])) {
            $record['extra'] = $this->redact($record['extra']);
        }

        if (isset($record['context'])) {
            $record['context'] = $this->redact($record['context']);
        }

        return $record;
    }

    private function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            $lower = strtolower($key);

            if (is_array($value)) {
                $data[$key] = $this->redact($value);
                continue;
            }

            if (in_array($lower, self::SENSITIVE_KEYS, true)) {
                $data[$key] = '***REDACTED***';
            }
        }

        return $data;
    }
}
