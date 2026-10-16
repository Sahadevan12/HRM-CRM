<?php

namespace Modules\Lead\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Modules\Lead\Models\Deal;
use Modules\Lead\Models\Lead;

/** A lead was converted to a deal. */
class LeadConverted
{
    use Dispatchable;

    public function __construct(public Lead $lead, public Deal $deal)
    {
    }
}
