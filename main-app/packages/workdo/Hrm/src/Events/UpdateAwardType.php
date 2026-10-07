<?php

namespace Workdo\Hrm\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Workdo\Hrm\Models\AwardType;

/** Fired after a award type is updated. Other modules can listen to it. */
class UpdateAwardType
{
    use Dispatchable;

    public function __construct(
        public Request $request,
        public AwardType $awardType,
    ) {
    }
}
