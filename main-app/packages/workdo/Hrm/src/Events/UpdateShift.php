<?php

namespace Workdo\Hrm\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Workdo\Hrm\Models\Shift;

/** Fired after a shift is updated. Other modules can listen to it. */
class UpdateShift
{
    use Dispatchable;

    public function __construct(
        public Request $request,
        public Shift $shift,
    ) {
    }
}
