<?php

namespace Workdo\Account\Providers;

use Illuminate\Support\ServiceProvider;
use Workdo\Account\Console\Commands\BackfillLedger;

class AccountServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $routesPath = __DIR__ . '/../Routes/web.php';
        if (file_exists($routesPath)) {
            $this->loadRoutesFrom($routesPath);
        }

        $migrationsPath = __DIR__ . '/../Database/Migrations';
        if (is_dir($migrationsPath)) {
            $this->loadMigrationsFrom($migrationsPath);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([BackfillLedger::class]);
        }
    }

    public function register(): void
    {
        $this->app->register(EventServiceProvider::class);
    }
}
