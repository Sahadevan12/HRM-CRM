<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Auto-discovers add-on modules in packages/workdo/* :
 *  - registers each module's PSR-4 namespace at runtime (no composer dump-autoload needed)
 *  - registers every provider listed in the module's composer.json extra.laravel.providers
 */
class PackageServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $loader = require base_path('vendor/autoload.php');

        foreach (glob(base_path('packages/workdo/*'), GLOB_ONLYDIR) as $packageDir) {
            $composerFile = $packageDir . '/composer.json';

            if (!file_exists($composerFile)) {
                continue;
            }

            $config = json_decode(file_get_contents($composerFile), true);

            foreach ($config['autoload']['psr-4'] ?? [] as $namespace => $path) {
                $loader->addPsr4($namespace, $packageDir . '/' . $path);
            }

            foreach ($config['extra']['laravel']['providers'] ?? [] as $provider) {
                $this->app->register($provider);
            }
        }
    }

    public function boot(): void
    {
        //
    }
}
