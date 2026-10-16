<?php

namespace Modules\Hrm\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Modules\Hrm\Models\LeaveType;

/** Fired after a leave type is deleted. Other modules can listen to it. */
class DestroyLeaveType
{
    use Dispatchable;

    public function __construct(
        public Request $request,
        public LeaveType $leaveType,
    ) {
    }
}
