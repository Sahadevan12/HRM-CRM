<?php

namespace Workdo\Lead\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Workdo\Lead\Models\Deal;
use Workdo\Lead\Models\Lead;

/** A lead was converted to a deal. */
class LeadConverted
{
    use Dispatchable;

    public function __construct(public Lead $lead, public Deal $deal)
    {
    }
}
