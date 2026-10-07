<?php

namespace Workdo\Hrm\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Workdo\Hrm\Models\Award;

/** Fired after a award is deleted. Other modules can listen to it. */
class DestroyAward
{
    use Dispatchable;

    public function __construct(
        public Request $request,
        public Award $award,
    ) {
    }
}
