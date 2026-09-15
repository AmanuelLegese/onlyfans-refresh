<?php

namespace App\Providers;

use App\Listeners\LongWaitListener;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Horizon\Events\LongWaitDetected;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(LongWaitDetected::class, LongWaitListener::class);
    }
}
