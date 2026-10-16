<?php

namespace Modules\Hrm\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Modules\Hrm\Models\SalaryComponent;

/** Fired after a salary component is updated. Other modules can listen to it. */
class UpdateSalaryComponent
{
    use Dispatchable;

    public function __construct(
        public Request $request,
        public SalaryComponent $salaryComponent,
    ) {
    }
}
