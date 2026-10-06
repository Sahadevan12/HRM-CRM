<?php

namespace Database\Seeders;

use App\Classes\Module;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $modules = new Module();
        $modules->sync();

        $this->call([
            PermissionRoleSeeder::class,
            PlanSeeder::class,
        ]);

        // every installed add-on registers its permissions (same as `php artisan package:seed <Module>`)
        foreach ($modules->installed() as $name) {
            $seeder = "Workdo\\{$name}\\Database\\Seeders\\PermissionTableSeeder";

            if (class_exists($seeder)) {
                $this->call($seeder);
            }
        }
    }
}
