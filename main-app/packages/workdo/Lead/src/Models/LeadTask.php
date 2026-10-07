<?php

namespace Workdo\Lead\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadTask extends Model
{
    protected $fillable = ['lead_id', 'name', 'due_date', 'due_time', 'priority', 'status', 'creator_id', 'created_by'];

    protected function casts(): array
    {
        return ['due_date' => 'date:Y-m-d'];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }
}
