<?php

namespace Modules\Hrm\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Modules\Hrm\Models\Branch;

/** Fired after a branch is created. Other modules can listen to it. */
class CreateBranch
{
    use Dispatchable;

    public function __construct(
        public Request $request,
        public Branch $branch,
    ) {
    }
}
