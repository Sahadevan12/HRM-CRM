<?php

namespace Workdo\Lead\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Workdo\Lead\Models\Deal;

/** A deal became won / lost / active again (hook for other modules, e.g. to draft a proposal when a deal is won). Dispatched inside the transaction. */
class DealStatusChanged
{
    use Dispatchable;

    public function __construct(public Deal $deal, public string $from, public string $to)
    {
    }
}
