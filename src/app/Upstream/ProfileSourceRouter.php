<?php

namespace App\Upstream;

use App\Models\Account;

/**
 * Picks the upstream per account: `onlyfans` accounts call the real API, everything else the
 * local fake upstream used by tests and workloads.
 */
class ProfileSourceRouter implements ProfileSource
{
    public function __construct(
        private FakeUpstreamClient $fake,
        private RealOnlyFansClient $onlyfans,
    ) {}

    public function fetch(Account $account, string $username): array
    {
        return $account->source === Account::SOURCE_ONLYFANS
            ? $this->onlyfans->fetch($account, $username)
            : $this->fake->fetch($account, $username);
    }
}
