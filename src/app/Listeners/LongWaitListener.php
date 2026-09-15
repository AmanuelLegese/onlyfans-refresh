<?php

namespace App\Listeners;

use Illuminate\Support\Facades\Log;
use Laravel\Horizon\Events\LongWaitDetected;

class LongWaitListener
{
    public function handle(LongWaitDetected $event): void
    {
        Log::channel('refresh')->warning('long_wait_detected', [
            'connection' => $event->connection,
            'queue' => $event->queue,
            'seconds' => $event->seconds,
        ]);
    }
}
