<?php

namespace Modules\Hrm\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Modules\Hrm\Models\AwardType;

/** Fired after a award type is deleted. Other modules can listen to it. */
class DestroyAwardType
{
    use Dispatchable;

    public function __construct(
        public Request $request,
        public AwardType $awardType,
    ) {
    }
}
