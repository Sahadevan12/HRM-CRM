<?php

namespace Workdo\Lead\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DealTask extends Model
{
    protected $fillable = ['deal_id', 'name', 'due_date', 'due_time', 'priority', 'status', 'creator_id', 'created_by'];

    protected function casts(): array
    {
        return ['due_date' => 'date:Y-m-d'];
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }
}
