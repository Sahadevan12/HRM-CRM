<?php

namespace Workdo\Lead\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadStage extends Model
{
    protected $fillable = ['name', 'pipeline_id', 'order', 'creator_id', 'created_by'];

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }
}
