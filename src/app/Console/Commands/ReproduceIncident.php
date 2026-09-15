<?php

namespace App\Console\Commands;

use App\Jobs\Legacy\LegacyRefreshProfile;
use App\Models\{Account, Profile};
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class ReproduceIncident extends Command
{
    protected $signature = 'incident:reproduce {--mode=legacy : legacy or fixed}';

    protected $description = 'Reproduce the upstream data bug through the legacy handler';

    public function handle(): int
    {
        $this->info("=== Incident Reproduction (mode: {$this->option('mode')}) ===");
        $this->newLine();

        $account = Account::firstOrCreate(
            ['name' => 'Test Account'],
            ['credentials' => ['token' => 'test-token'], 'max_concurrency' => 2]
        );

        $scenarios = [
            'old-format' => [
                'username' => 'old_format_user',
                'response' => [
                    'username' => 'old_format_user',
                    'likes' => 120000,
                    'revision' => 10,
                    'name' => 'Old Format User',
                    'avatar_url' => 'https://example.com/old.jpg',
                ],
            ],
            'new-format' => [
                'username' => 'new_format_user',
                'response' => [
                    'username' => 'new_format_user',
                    'profile' => ['likes' => 121000],
                    'revision' => 11,
                    'name' => 'New Format User',
                    'avatar_url' => 'https://example.com/new.jpg',
                ],
            ],
            'missing-likes' => [
                'username' => 'missing_likes_user',
                'response' => [
                    'username' => 'missing_likes_user',
                    'revision' => 11,
                    'name' => 'Missing Likes User',
                ],
            ],
            'empty-500' => [
                'username' => 'empty_500_user',
                'response' => null,
                'status' => 500,
            ],
        ];

        foreach ($scenarios as $name => $data) {
            $this->info("--- Scenario: {$name} ---");

            $profile = Profile::firstOrCreate(
                ['username' => $data['username']],
                [
                    'account_id' => $account->id,
                    'likes' => 120000,
                    'revision' => 10,
                    'last_success_at' => now()->subHour(),
                ]
            );

            $this->table(
                ['Field', 'Before'],
                [
                    ['likes', $profile->likes],
                    ['revision', $profile->revision],
                    ['last_attempt_outcome', $profile->last_attempt_outcome ?? 'null'],
                ]
            );

            // Fake the HTTP response
            if (isset($data['status']) && $data['status'] >= 400) {
                Http::fake([
                    '*' => Http::response([], $data['status']),
                ]);
            } elseif ($data['response'] !== null) {
                Http::fake([
                    '*' => Http::response($data['response'], 200),
                ]);
            } else {
                Http::fake(function () {
                    throw new \Illuminate\Http\Client\ConnectionException('Connection refused');
                });
            }

            $job = new LegacyRefreshProfile($profile->id);
            try {
                $job->handle();
                $profile->refresh();

                $this->info('After job:');
                $this->table(
                    ['Field', 'After'],
                    [
                        ['likes', $profile->likes],
                        ['revision', $profile->revision],
                        ['last_attempt_outcome', $profile->last_attempt_outcome ?? 'null'],
                        ['last_success_at', $profile->last_success_at?->toISOString() ?? 'null'],
                    ]
                );
            } catch (\Throwable $e) {
                $this->error("Job failed: {$e->getMessage()}");
            }

            $this->newLine();
        }

        $this->info('=== Summary ===');
        $this->info('The legacy handler:');
        $this->info('  1. Reads top-level "likes" and defaults missing values to 0');
        $this->info('  2. Never checks the profile{} wrapper');
        $this->info('  3. Marks every response as a success');
        $this->info('  4. Does not handle 429, 500, timeouts, or malformed responses');
        $this->info('  5. Does not create refresh_attempts rows');

        return self::SUCCESS;
    }
}
