<?php

namespace App\Console\Commands;

use App\Jobs\Legacy\LegacyRefreshProfile;
use App\Jobs\RefreshProfile;
use App\Models\Account;
use App\Models\Profile;
use App\Upstream\FakeUpstreamClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

class ReproduceIncident extends Command
{
    protected $signature = 'incident:reproduce {--mode=both : legacy, fixed or both}';

    protected $description = 'Run the incident responses through the legacy and/or fixed handler and show what gets stored';

    /** Last valid state before each scenario: likes 120,000 at revision 9 (so the revision-10 old-format response is new data). */
    private const SEEDED_SUCCESS_AT = '2026-01-01 00:00:00';

    public function handle(): int
    {
        $modes = match ($this->option('mode')) {
            'both' => ['legacy', 'fixed'],
            'legacy' => ['legacy'],
            'fixed' => ['fixed'],
            default => null,
        };

        if ($modes === null) {
            $this->error('--mode must be legacy, fixed or both');

            return self::FAILURE;
        }

        $account = Account::firstOrCreate(
            ['name' => 'Incident Reproduction'],
            ['credentials' => ['token' => 'incident-token'], 'max_concurrency' => 2],
        );

        // One fake for the whole run; $current is swapped per request. Calling Http::fake()
        // once per scenario would stack stubs, and the first stub would answer every request.
        $current = null;
        Http::fake(function () use (&$current) {
            return Http::response($current['body'], $current['status'], $current['headers'] ?? []);
        });

        $this->line('Each scenario starts from the last valid state: likes=120000, revision=9, last_success_at='.self::SEEDED_SUCCESS_AT);
        $this->newLine();

        foreach ($modes as $mode) {
            $rows = [];

            foreach ($this->scenarios() as $name => $responses) {
                $profile = $this->resetProfile($account, "incident_{$name}");

                foreach ($responses as $response) {
                    $current = $response;
                    $error = $this->runHandler($mode, $profile->id);
                }

                $profile->refresh();
                $rows[] = [
                    $name,
                    implode(' then ', array_map(fn ($r) => $r['label'], $responses)),
                    $profile->likes ?? 'null',
                    $profile->revision ?? 'null',
                    $profile->last_attempt_outcome ?? 'null',
                    $profile->last_success_at?->equalTo(Carbon::parse(self::SEEDED_SUCCESS_AT)) ? 'no' : 'YES',
                    $error ?? ($profile->last_failure_reason ?? '-'),
                ];
            }

            $this->info("=== {$mode} handler ===");
            $this->table(
                ['Scenario', 'Upstream response', 'likes', 'revision', 'last_attempt_outcome', 'last_success_at changed', 'failure / error'],
                $rows,
            );
            $this->newLine();
        }

        return self::SUCCESS;
    }

    /** @return array<string, list<array{label: string, status: int, body: mixed}>> */
    private function scenarios(): array
    {
        $old = ['label' => '200 {likes:120000, revision:10}', 'status' => 200, 'body' => ['likes' => 120000, 'revision' => 10]];
        $new = ['label' => '200 {profile:{likes:121000}, revision:11}', 'status' => 200, 'body' => ['profile' => ['likes' => 121000], 'revision' => 11]];

        return [
            'old_format' => [$old],
            'new_format' => [$new],
            'rate_limited' => [['label' => '429 no Retry-After', 'status' => 429, 'body' => ['error' => 'Too Many Requests']]],
            'server_error' => [['label' => '500 empty body', 'status' => 500, 'body' => '']],
            'older_revision_last' => [$new, $old],
        ];
    }

    private function resetProfile(Account $account, string $username): Profile
    {
        $profile = Profile::updateOrCreate(['username' => $username], ['account_id' => $account->id]);

        // forceFill: reset every refresh field so repeated runs start from the same state.
        $profile->forceFill([
            'likes' => 120000,
            'revision' => 9,
            'last_attempt_at' => null,
            'last_attempt_outcome' => null,
            'last_success_at' => Carbon::parse(self::SEEDED_SUCCESS_AT),
            'last_failure_at' => null,
            'last_failure_reason' => null,
            'last_failure_detail' => null,
            'consecutive_failures' => 0,
        ])->save();

        return $profile;
    }

    /** Runs the handler directly (no queue, no middleware). Returns an error message if it threw. */
    private function runHandler(string $mode, int $profileId): ?string
    {
        try {
            if ($mode === 'legacy') {
                (new LegacyRefreshProfile($profileId))->handle();
            } else {
                (new RefreshProfile($profileId, 'fixed'))->handle(app(FakeUpstreamClient::class));
            }

            return null;
        } catch (\Throwable $e) {
            return 'exception: '.class_basename($e).': '.$e->getMessage();
        }
    }
}
