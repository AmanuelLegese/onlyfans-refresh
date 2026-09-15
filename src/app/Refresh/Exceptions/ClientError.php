<?php

namespace App\Refresh\Exceptions;

class ClientError extends UpstreamException
{
    public function __construct(int $status, ?\Throwable $previous = null)
    {
        parent::__construct("Upstream client error: {$status}", $status, $previous);
    }
}
