<?php

namespace App\Console\Commands;

use App\Services\PackageGenerator;
use Illuminate\Console\Command;
use InvalidArgumentException;

class MakePackage extends Command
{
    protected $signature = 'make:package
        {name : Module name in PascalCase, e.g. Hrm or SupportTicket}
        {--alias= : Display name (default: headline of the name)}
        {--priority=50 : Module / menu ordering}
        {--entity=* : CRUD entity to scaffold right away (repeatable). Use make:crud for custom fields}';

    protected $description = 'Scaffold a new add-on module in packages/workdo (provider, routes, permissions, menu, React page)';

    public function handle(PackageGenerator $generator): int
    {
        $name = $this->argument('name');

        try {
            $files = $generator->makePackage($name, $this->option('alias') ?: null, (int) $this->option('priority'));

            foreach ($this->option('entity') as $entity) {
                $files = array_merge($files, $generator->makeCrud($name, $entity));
            }
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($files as $file) {
            $this->line("  <info>+</info> packages/workdo/{$name}/{$file}");
        }

        $this->newLine();
        $this->info("Module {$name} created. Next steps:");
        $this->line('  php artisan migrate');
        $this->line('  php artisan package:sync');
        $this->line("  php artisan package:seed {$name}");
        $this->line("  Add {$name} to a plan (Plans page), then: npm run build");

        return self::SUCCESS;
    }
}
