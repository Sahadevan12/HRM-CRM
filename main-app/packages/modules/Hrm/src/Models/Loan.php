<?php

namespace Modules\Hrm\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Loan extends Model
{
    public const STATUSES = ['active', 'completed', 'cancelled'];

    protected $fillable = ['employee_id', 'title', 'amount', 'installment', 'repaid', 'start_month', 'status', 'disbursed_from', 'disbursed_on', 'notes', 'creator_id', 'created_by'];

    protected function casts(): array
    {
        return ['amount' => 'float', 'installment' => 'float', 'repaid' => 'float', 'start_month' => 'date:Y-m-d', 'disbursed_on' => 'date:Y-m-d'];
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
