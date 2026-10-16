<?php

namespace Modules\Lead\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Modules\Lead\Models\Lead;

/** Fired after the item was saved (hook for notifications / integrations of other modules). */
class LeadFileUploaded
{
    use Dispatchable;

    public function __construct(public Lead $lead, public Model $item)
    {
    }
}
