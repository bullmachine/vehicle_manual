<?php

namespace App\Providers;

use App\Services\DatabaseMachineLookup;
use App\Services\MachineLookup;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Machines are synced into the local table from SAP (php artisan machines:sync); lookups read that table.
        $this->app->bind(MachineLookup::class, DatabaseMachineLookup::class);
    }

    public function boot(): void
    {
        date_default_timezone_set(config('app.timezone', 'UTC'));
    }
}
