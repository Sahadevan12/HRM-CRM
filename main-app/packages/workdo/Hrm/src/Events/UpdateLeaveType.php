<?php

namespace Workdo\Hrm\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Workdo\Hrm\Models\LeaveType;

/** Fired after a leave type is updated. Other modules can listen to it. */
class UpdateLeaveType
{
    use Dispatchable;

    public function __construct(
        public Request $request,
        public LeaveType $leaveType,
    ) {
    }
}
