<?php

namespace App\Providers;

use App\Services\FakeSmsProvider;
use App\Services\SmsProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SmsProvider::class, FakeSmsProvider::class);
    }

    public function boot(): void
    {
        date_default_timezone_set(config('app.timezone', 'UTC'));
    }
}
