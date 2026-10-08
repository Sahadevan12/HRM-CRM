<?php

namespace Workdo\Lead\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Workdo\Lead\Exceptions\LeadException;
use Workdo\Lead\Services\DemoData;

class CrmDemo extends Command
{
    protected $signature = 'crm:demo {email : e-mail of the company (owner) to fill} {--force : add the demo data even if the company already has leads or deals}';

    protected $description = 'Fill a company with sample CRM leads and deals (for demos)';

    public function handle(DemoData $demo): int
    {
        $company = User::where('email', $this->argument('email'))->where('type', 'company')->first();

        if (!$company) {
            $this->error('No company with that e-mail address.');

            return self::FAILURE;
        }

        try {
            $made = $demo->seed($company->id, $company->id, (bool) $this->option('force'));
        } catch (LeadException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Created {$made['leads']} leads and {$made['deals']} deals for {$company->email}.");

        return self::SUCCESS;
    }
}
