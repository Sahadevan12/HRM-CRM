<?php

namespace Modules\Hrm\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Modules\Hrm\Models\LeaveType;

/** Fired after a leave type is created. Other modules can listen to it. */
class CreateLeaveType
{
    use Dispatchable;

    public function __construct(
        public Request $request,
        public LeaveType $leaveType,
    ) {
    }
}
