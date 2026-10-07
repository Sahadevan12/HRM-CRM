<?php

namespace Workdo\Lead\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Label extends Model
{
    protected $fillable = ['name', 'color', 'pipeline_id', 'creator_id', 'created_by'];

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }
}
