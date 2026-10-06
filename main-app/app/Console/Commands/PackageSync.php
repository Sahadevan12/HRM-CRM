<?php

namespace App\Console\Commands;

use App\Classes\Module;
use Illuminate\Console\Command;

class PackageSync extends Command
{
    protected $signature = 'package:sync';

    protected $description = 'Register/refresh every packages/workdo/* module (module.json) in the add_ons table';

    public function handle(): int
    {
        $modules = new Module();
        $modules->sync();

        $this->info('Synced add-ons: ' . (implode(', ', $modules->installed()) ?: 'none'));

        return self::SUCCESS;
    }
}
