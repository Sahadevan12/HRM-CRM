<?php

namespace Modules\Hrm\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Modules\Hrm\Models\Award;

/** Fired after a award is created. Other modules can listen to it. */
class CreateAward
{
    use Dispatchable;

    public function __construct(
        public Request $request,
        public Award $award,
    ) {
    }
}
