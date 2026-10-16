<?php

namespace Modules\Hrm\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Modules\Hrm\Models\Designation;

/** Fired after a designation is created. Other modules can listen to it. */
class CreateDesignation
{
    use Dispatchable;

    public function __construct(
        public Request $request,
        public Designation $designation,
    ) {
    }
}
