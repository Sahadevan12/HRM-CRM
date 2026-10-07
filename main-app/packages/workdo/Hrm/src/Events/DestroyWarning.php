<?php

namespace Workdo\Hrm\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Workdo\Hrm\Models\Warning;

/** Fired after a warning is deleted. Other modules can listen to it. */
class DestroyWarning
{
    use Dispatchable;

    public function __construct(
        public Request $request,
        public Warning $warning,
    ) {
    }
}
