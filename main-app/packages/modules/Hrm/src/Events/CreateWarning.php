<?php

namespace Modules\Hrm\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Modules\Hrm\Models\Warning;

/** Fired after a warning is created. Other modules can listen to it. */
class CreateWarning
{
    use Dispatchable;

    public function __construct(
        public Request $request,
        public Warning $warning,
    ) {
    }
}
