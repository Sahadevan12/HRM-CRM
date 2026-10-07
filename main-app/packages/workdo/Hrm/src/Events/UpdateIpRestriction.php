<?php

namespace Workdo\Hrm\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Workdo\Hrm\Models\IpRestriction;

/** Fired after a ip restriction is updated. Other modules can listen to it. */
class UpdateIpRestriction
{
    use Dispatchable;

    public function __construct(
        public Request $request,
        public IpRestriction $ipRestriction,
    ) {
    }
}
