<?php

namespace Workdo\Hrm\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Workdo\Hrm\Models\Designation;

/** Fired after a designation is deleted. Other modules can listen to it. */
class DestroyDesignation
{
    use Dispatchable;

    public function __construct(
        public Request $request,
        public Designation $designation,
    ) {
    }
}
