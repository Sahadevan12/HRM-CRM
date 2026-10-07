<?php

namespace Workdo\Hrm\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Termination extends Model
{
    protected $fillable = ['employee_id', 'type', 'notice_date', 'termination_date', 'reason', 'status', 'decision_comment', 'decided_by', 'decided_at', 'applied_at', 'creator_id', 'created_by'];

    protected function casts(): array
    {
        return ['notice_date' => 'date:Y-m-d', 'termination_date' => 'date:Y-m-d', 'decided_at' => 'datetime', 'applied_at' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
