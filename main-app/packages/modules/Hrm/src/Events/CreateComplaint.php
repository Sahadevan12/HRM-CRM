<?php

namespace Modules\Hrm\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Modules\Hrm\Models\Complaint;

/** Fired after a complaint is created. Other modules can listen to it. */
class CreateComplaint
{
    use Dispatchable;

    public function __construct(
        public Request $request,
        public Complaint $complaint,
    ) {
    }
}
