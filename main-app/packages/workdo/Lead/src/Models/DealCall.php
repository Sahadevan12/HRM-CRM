<?php

namespace Workdo\Lead\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DealCall extends Model
{
    protected $fillable = ['deal_id', 'subject', 'call_type', 'duration_minutes', 'description', 'result', 'creator_id', 'created_by'];

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }
}
