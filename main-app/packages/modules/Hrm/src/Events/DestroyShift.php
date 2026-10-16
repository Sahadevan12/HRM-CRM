<?php

namespace Modules\Hrm\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Modules\Hrm\Models\Shift;

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
