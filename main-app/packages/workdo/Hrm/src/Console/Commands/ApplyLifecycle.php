<?php

namespace Workdo\Hrm\Console\Commands;

use Illuminate\Console\Command;
use Workdo\Hrm\Services\LifecycleService;

/** Applies approved resignations / terminations / transfers whose effective date has come. Scheduled daily by the Hrm provider. */
class ApplyLifecycle extends Command
{
    protected $signature = 'hrm:apply-lifecycle';

    protected $description = 'Apply approved resignations, terminations and transfers that are due today';

    public function handle(LifecycleService $lifecycle): int
    {
        $this->info(__(':n request(s) applied.', ['n' => $lifecycle->applyDue()]));

        return self::SUCCESS;
    }
}
