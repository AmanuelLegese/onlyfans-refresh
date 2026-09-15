<?php

namespace App\Upstream;

use App\Models\Account;

interface ProfileSource
{
    /**
     * Fetch profile data from the upstream API.
     *
     * @return array{profile: array, revision: int, likes: int, ...}
     *
     * @throws \App\Refresh\Exceptions\UpstreamException
     */
    public function fetch(Account $account, string $username): array;
}
