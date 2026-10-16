<?php

namespace Modules\Hrm\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Modules\Hrm\Models\EventType;

/** Fired after a event type is created. Other modules can listen to it. */
class CreateEventType
{
    use Dispatchable;

    public function __construct(
        public Request $request,
        public EventType $eventType,
    ) {
    }
}
