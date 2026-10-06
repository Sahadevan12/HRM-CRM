<?php

namespace App\Console\Commands;

use App\Classes\Module;
use Illuminate\Console\Command;

class PackageSeed extends Command
{
    protected $signature = 'package:seed {packageName : Module folder name, e.g. Hrm}';

    protected $description = "Run a module's PermissionTableSeeder (creates its permissions and gives them to the company role)";

    public function handle(): int
    {
        $name = $this->argument('packageName');

        if (!(new Module())->has($name)) {
            $this->error("Module '{$name}' not found in packages/workdo.");

            return self::FAILURE;
        }

        $seeder = "Workdo\\{$name}\\Database\\Seeders\\PermissionTableSeeder";

        if (!class_exists($seeder)) {
            $this->warn("{$name} has no PermissionTableSeeder – nothing to seed.");

            return self::SUCCESS;
        }

        $this->call('db:seed', ['--class' => $seeder, '--force' => true]);

        return self::SUCCESS;
    }
}
