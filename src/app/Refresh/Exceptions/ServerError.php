<?php

namespace App\Refresh\Exceptions;

class ServerError extends UpstreamException
{
    public function __construct(int $status, ?\Throwable $previous = null)
    {
        parent::__construct("Upstream server error: {$status}", $status, $previous);
    }
}
