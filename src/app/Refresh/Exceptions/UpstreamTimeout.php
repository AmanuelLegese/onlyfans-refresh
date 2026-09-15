<?php

namespace App\Refresh\Exceptions;

class UpstreamTimeout extends UpstreamException
{
    public function __construct(\Throwable $previous = null)
    {
        parent::__construct('Upstream timeout', 0, $previous);
    }
}
