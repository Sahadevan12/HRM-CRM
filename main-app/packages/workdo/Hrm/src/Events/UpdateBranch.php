<?php

namespace Workdo\Hrm\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Workdo\Hrm\Models\Branch;

/** Fired after a branch is updated. Other modules can listen to it. */
class UpdateBranch
{
    use Dispatchable;

    public function __construct(
        public Request $request,
        public Branch $branch,
    ) {
    }
}
