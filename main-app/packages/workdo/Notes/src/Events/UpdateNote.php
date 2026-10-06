<?php

namespace Workdo\Notes\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Workdo\Notes\Models\Note;

/** Fired after a note is updated. Other modules can listen to it. */
class UpdateNote
{
    use Dispatchable;

    public function __construct(
        public Request $request,
        public Note $note,
    ) {
    }
}
