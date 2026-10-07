<?php

namespace Workdo\Hrm\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Workdo\Hrm\Models\SalaryComponent;

/** Fired after a salary component is created. Other modules can listen to it. */
class CreateSalaryComponent
{
    use Dispatchable;

    public function __construct(
        public Request $request,
        public SalaryComponent $salaryComponent,
    ) {
    }
}
