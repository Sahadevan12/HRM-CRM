<?php

namespace Workdo\Hrm\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payslip extends Model
{
    protected $fillable = ['payroll_id', 'employee_id', 'basic_salary', 'working_days', 'unpaid_days', 'overtime_hours', 'earnings', 'deductions', 'net_pay'];

    protected function casts(): array
    {
        return ['basic_salary' => 'float', 'unpaid_days' => 'float', 'overtime_hours' => 'float', 'earnings' => 'float', 'deductions' => 'float', 'net_pay' => 'float'];
    }

    public function payroll(): BelongsTo
    {
        return $this->belongsTo(Payroll::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PayslipLine::class);
    }
}
