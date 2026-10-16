<?php

namespace Modules\Hrm\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Modules\Hrm\Models\IpRestriction;

/** Fired after a ip restriction is deleted. Other modules can listen to it. */
class DestroyIpRestriction
{
    use Dispatchable;

    public function __construct(
        public Request $request,
        public IpRestriction $ipRestriction,
    ) {
    }
}
