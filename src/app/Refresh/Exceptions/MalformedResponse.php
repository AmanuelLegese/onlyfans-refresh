<?php

namespace App\Refresh\Exceptions;

class MalformedResponse extends UpstreamException
{
    public function __construct(string $detail = '', ?\Throwable $previous = null)
    {
        parent::__construct("Malformed upstream response: {$detail}", 0, $previous);
    }
}
