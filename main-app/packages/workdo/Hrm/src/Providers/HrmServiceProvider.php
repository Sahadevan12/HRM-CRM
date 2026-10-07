<?php

namespace Workdo\Hrm\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Workdo\Hrm\Console\Commands\ApplyLifecycle;

class HrmServiceProvider extends ServiceProvider
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
            $this->commands([ApplyLifecycle::class]);

            $this->callAfterResolving(Schedule::class, fn (Schedule $schedule) => $schedule->command('hrm:apply-lifecycle')->dailyAt('00:10')->withoutOverlapping());
        }
    }

    public function register(): void
    {
        $this->app->register(EventServiceProvider::class);
    }
}
