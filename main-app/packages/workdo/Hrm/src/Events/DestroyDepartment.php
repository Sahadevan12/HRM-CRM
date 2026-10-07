<?php

namespace Workdo\Hrm\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Workdo\Hrm\Models\Department;

/** Fired after a department is deleted. Other modules can listen to it. */
class DestroyDepartment
{
    use Dispatchable;

    public function __construct(
        public Request $request,
        public Department $department,
    ) {
    }
}
