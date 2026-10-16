<?php

namespace Modules\Hrm\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Modules\Hrm\Models\Branch;

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
