<?php

namespace Workdo\Hrm\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveApplication extends Model
{
    public const STATUSES = ['pending', 'approved', 'rejected'];

    protected $table = 'leave_applications';

    protected $fillable = [
        'employee_id', 'leave_type_id', 'start_date', 'end_date', 'total_days', 'reason', 'status', 'approver_comment',
        'approved_by', 'approved_at', 'creator_id', 'created_by',
    ];

    protected function casts(): array
    {
        return ['start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d', 'total_days' => 'float', 'approved_at' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
