<?php

namespace Workdo\Hrm\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    public const GENDERS = ['male', 'female', 'other'];
    public const EMPLOYMENT_TYPES = ['full_time', 'part_time', 'contract', 'intern'];
    public const STATUSES = ['active', 'inactive', 'resigned', 'terminated'];

    protected $table = 'employees';

    protected $fillable = [
        'user_id', 'employee_code', 'branch_id', 'department_id', 'designation_id', 'shift_id', 'date_of_birth', 'gender',
        'date_of_joining', 'employment_type', 'status', 'phone', 'address_line', 'city', 'state', 'country',
        'postal_code', 'emergency_name', 'emergency_relationship', 'emergency_phone', 'bank_name', 'account_holder',
        'account_number', 'bank_code', 'tax_id', 'basic_salary', 'hourly_rate', 'notes', 'creator_id', 'created_by',
    ];

    /** Never sent to the browser in a list: bank details and tax id are only on the employee's own profile page. */
    protected $hidden = ['account_number', 'bank_code', 'tax_id'];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date:Y-m-d',
            'date_of_joining' => 'date:Y-m-d',
            'basic_salary' => 'float',
            'hourly_rate' => 'float',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
