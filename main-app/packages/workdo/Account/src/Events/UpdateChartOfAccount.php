<?php

namespace Workdo\Account\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Workdo\Account\Models\ChartOfAccount;

/** Fired after a chart of account is updated. Other modules can listen to it. */
class UpdateChartOfAccount
{
    use Dispatchable;

    public function __construct(
        public Request $request,
        public ChartOfAccount $chartOfAccount,
    ) {
    }
}
