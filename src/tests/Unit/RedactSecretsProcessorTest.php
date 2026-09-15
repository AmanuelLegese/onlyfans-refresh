<?php

use App\Logging\RedactSecretsProcessor;
use Monolog\Level;
use Monolog\LogRecord;

it('redacts sensitive keys in context', function () {
    $processor = new RedactSecretsProcessor();

    $record = new LogRecord(
        datetime: new \DateTimeImmutable(),
        channel: 'refresh',
        level: Level::Info,
        message: 'test',
        context: [
            'token' => 'secret-123',
            'password' => 'hunter2',
            'authorization' => 'Bearer abc',
            'username' => 'normal_user',
        ],
    );

    $result = $processor($record->toArray());

    expect($result['context']['token'])->toBe('***REDACTED***');
    expect($result['context']['password'])->toBe('***REDACTED***');
    expect($result['context']['authorization'])->toBe('***REDACTED***');
    expect($result['context']['username'])->toBe('normal_user');
});

it('redacts nested sensitive keys', function () {
    $processor = new RedactSecretsProcessor();

    $record = new LogRecord(
        datetime: new \DateTimeImmutable(),
        channel: 'refresh',
        level: Level::Info,
        message: 'test',
        context: [
            'credentials' => [
                'token' => 'secret-token',
                'name' => 'test',
            ],
        ],
    );

    $result = $processor($record->toArray());

    expect($result['context']['credentials']['token'])->toBe('***REDACTED***');
    expect($result['context']['credentials']['name'])->toBe('test');
});

it('redacts sensitive keys in extra', function () {
    $processor = new RedactSecretsProcessor();

    $record = new LogRecord(
        datetime: new \DateTimeImmutable(),
        channel: 'refresh',
        level: Level::Info,
        message: 'test',
        extra: [
            'api_key' => 'sk-123',
            'job_id' => 'abc-123',
        ],
    );

    $result = $processor($record->toArray());

    expect($result['extra']['api_key'])->toBe('***REDACTED***');
    expect($result['extra']['job_id'])->toBe('abc-123');
});

it('is case-insensitive for sensitive keys', function () {
    $processor = new RedactSecretsProcessor();

    $record = new LogRecord(
        datetime: new \DateTimeImmutable(),
        channel: 'refresh',
        level: Level::Info,
        message: 'test',
        context: [
            'TOKEN' => 'secret',
            'Api_Key' => 'key',
            'Client_Secret' => 's',
        ],
    );

    $result = $processor($record->toArray());

    expect($result['context']['TOKEN'])->toBe('***REDACTED***');
    expect($result['context']['Api_Key'])->toBe('***REDACTED***');
    expect($result['context']['Client_Secret'])->toBe('***REDACTED***');
});
