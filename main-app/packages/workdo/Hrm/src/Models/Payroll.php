<?php

namespace Workdo\Hrm\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payroll extends Model
{
    protected $fillable = ['month', 'status', 'total_earnings', 'total_deductions', 'total_net', 'paid_on', 'approved_by', 'approved_at', 'creator_id', 'created_by'];

    protected function casts(): array
    {
        return ['total_earnings' => 'float', 'total_deductions' => 'float', 'total_net' => 'float', 'paid_on' => 'date:Y-m-d', 'approved_at' => 'datetime'];
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }
}
