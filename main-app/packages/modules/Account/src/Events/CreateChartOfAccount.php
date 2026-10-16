<?php

namespace Modules\Account\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Modules\Account\Models\ChartOfAccount;

/** Fired after a chart of account is created. Other modules can listen to it. */
class CreateChartOfAccount
{
    use Dispatchable;

    public function __construct(
        public Request $request,
        public ChartOfAccount $chartOfAccount,
    ) {
    }
}
