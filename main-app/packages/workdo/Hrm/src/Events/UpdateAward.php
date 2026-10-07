<?php

namespace Workdo\Hrm\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Workdo\Hrm\Models\Award;

/** Fired after a award is updated. Other modules can listen to it. */
class UpdateAward
{
    use Dispatchable;

    public function __construct(
        public Request $request,
        public Award $award,
    ) {
    }
}
