<?php

namespace Workdo\Hrm\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Loan extends Model
{
    public const STATUSES = ['active', 'completed', 'cancelled'];

    protected $fillable = ['employee_id', 'title', 'amount', 'installment', 'repaid', 'start_month', 'status', 'notes', 'creator_id', 'created_by'];

    protected function casts(): array
    {
        return ['amount' => 'float', 'installment' => 'float', 'repaid' => 'float', 'start_month' => 'date:Y-m-d'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function remaining(): float
    {
        return round($this->amount - $this->repaid, 2);
    }
}
