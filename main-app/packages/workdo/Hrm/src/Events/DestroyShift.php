<?php

namespace Workdo\Hrm\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Workdo\Hrm\Models\Shift;

/** Fired after a shift is deleted. Other modules can listen to it. */
class DestroyShift
{
    use Dispatchable;

    public function __construct(
        public Request $request,
        public Shift $shift,
    ) {
    }
}
