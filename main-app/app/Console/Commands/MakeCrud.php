<?php

namespace App\Console\Commands;

use App\Services\PackageGenerator;
use Illuminate\Console\Command;
use InvalidArgumentException;

class MakeCrud extends Command
{
    protected $signature = 'make:crud
        {module : Existing module name, e.g. Hrm}
        {entity : Entity name, e.g. Employee or LeaveType}
        {--fields= : "name:string,salary:decimal?,joined_on:date,notes:text?,is_active:boolean" (? = nullable)}';

    protected $description = 'Add a tenant-scoped CRUD entity (model, controller, request, events, migration, routes, permissions, menu, React page) to a module';

    public function handle(PackageGenerator $generator): int
    {
        try {
            $files = $generator->makeCrud($this->argument('module'), $this->argument('entity'), (string) $this->option('fields'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($files as $file) {
            $this->line("  <info>+</info> packages/workdo/{$this->argument('module')}/{$file}");
        }

        $this->newLine();
        $this->info('Entity created. Next steps:');
        $this->line('  php artisan migrate');
        $this->line("  php artisan package:seed {$this->argument('module')}");
        $this->line('  npm run build   (or keep npm run dev running)');

        return self::SUCCESS;
    }
}
