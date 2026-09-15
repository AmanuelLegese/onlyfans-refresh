<?php

namespace App\Providers;

use App\Listeners\LongWaitListener;
use App\Upstream\ProfileSource;
use App\Upstream\ProfileSourceRouter;
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
        $this->app->bind(ProfileSource::class, ProfileSourceRouter::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(LongWaitDetected::class, LongWaitListener::class);
    }
}
