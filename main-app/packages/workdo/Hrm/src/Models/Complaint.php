<?php

namespace Workdo\Hrm\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Complaint extends Model
{
    protected $table = 'complaints';

    protected $fillable = [
        'from_employee_id',
        'against_employee_id',
        'subject',
        'complaint_date',
        'description',
        'status',
        'resolution',
        'creator_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'complaint_date' => 'date:Y-m-d',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function fromEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'from_employee_id');
    }

    public function againstEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'against_employee_id');
    }
}
