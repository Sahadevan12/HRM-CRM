<?php

namespace Workdo\Hrm\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeSalaryComponent extends Model
{
    protected $fillable = ['employee_id', 'salary_component_id', 'value', 'created_by'];

    protected function casts(): array
    {
        return ['value' => 'float'];
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(SalaryComponent::class, 'salary_component_id');
    }
}
