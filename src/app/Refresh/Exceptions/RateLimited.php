<?php

namespace App\Refresh\Exceptions;

class RateLimited extends UpstreamException
{
    protected int $retryAfter;

    public function __construct(int $retryAfter = 0, ?\Throwable $previous = null)
    {
        $this->retryAfter = $retryAfter;
        parent::__construct("Rate limited, retry after {$retryAfter}s", 429, $previous);
    }

    public function getRetryAfter(): int
    {
        return $this->retryAfter;
    }
}
