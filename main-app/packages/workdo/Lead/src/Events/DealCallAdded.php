<?php

namespace Workdo\Lead\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Workdo\Lead\Models\Deal;

/** Fired after the item was saved (hook for notifications / integrations of other modules). */
class DealCallAdded
{
    use Dispatchable;

    public function __construct(public Deal $deal, public Model $item)
    {
    }
}
