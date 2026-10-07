<?php

namespace Workdo\Hrm\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Workdo\Hrm\Models\EventType;

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
