<?php

namespace App\Refresh\Exceptions;

/**
 * OnlyFans rejected the request signature (401/403), usually because the signing rules rotated.
 * Retryable: the cached rules are dropped so the next attempt signs with fresh rules.
 */
class SignatureRejected extends UpstreamException
{
    public function __construct(int $status, ?\Throwable $previous = null)
    {
        parent::__construct("Upstream rejected the request signature: {$status}", $status, $previous);
    }
}
