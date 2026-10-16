<?php

namespace Modules\Hrm\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Modules\Hrm\Models\Holiday;

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
