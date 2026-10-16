<?php

namespace Modules\Hrm\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Modules\Hrm\Models\Branch;

/** Fired after a branch is deleted. Other modules can listen to it. */
class DestroyBranch
{
    use Dispatchable;

    public function __construct(
        public Request $request,
        public Branch $branch,
    ) {
    }
}
