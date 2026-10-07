<?php

namespace Workdo\Hrm\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Workdo\Hrm\Models\Designation;

/** Fired after a designation is updated. Other modules can listen to it. */
class UpdateDesignation
{
    use Dispatchable;

    public function __construct(
        public Request $request,
        public Designation $designation,
    ) {
    }
}
