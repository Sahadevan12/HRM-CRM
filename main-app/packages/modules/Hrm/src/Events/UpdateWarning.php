<?php

namespace Modules\Hrm\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Modules\Hrm\Models\Warning;

/** Fired after a warning is updated. Other modules can listen to it. */
class UpdateWarning
{
    use Dispatchable;

    public function __construct(
        public Request $request,
        public Warning $warning,
    ) {
    }
}
