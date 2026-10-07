<?php

namespace Workdo\Hrm\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transfer extends Model
{
    protected $fillable = ['employee_id', 'from_branch_id', 'from_department_id', 'branch_id', 'department_id', 'transfer_date', 'reason', 'status', 'decision_comment', 'decided_by', 'decided_at', 'applied_at', 'creator_id', 'created_by'];

    protected function casts(): array
    {
        return ['transfer_date' => 'date:Y-m-d', 'decided_at' => 'datetime', 'applied_at' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function fromBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'from_branch_id');
    }

    public function fromDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'from_department_id');
    }
}
