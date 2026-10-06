<?php

namespace Workdo\Notes\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Workdo\Notes\Models\Notebook;

/** Fired after a notebook is created. Other modules can listen to it. */
class CreateNotebook
{
    use Dispatchable;

    public function __construct(
        public Request $request,
        public Notebook $notebook,
    ) {
    }
}
