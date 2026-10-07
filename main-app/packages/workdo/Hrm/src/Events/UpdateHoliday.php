<?php

namespace Workdo\Hrm\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Workdo\Hrm\Models\Holiday;

/** Fired after a holiday is updated. Other modules can listen to it. */
class UpdateHoliday
{
    use Dispatchable;

    public function __construct(
        public Request $request,
        public Holiday $holiday,
    ) {
    }
}
