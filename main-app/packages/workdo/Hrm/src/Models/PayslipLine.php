<?php

namespace Workdo\Hrm\Models;

use Illuminate\Database\Eloquent\Model;

class PayslipLine extends Model
{
    public $timestamps = false;

    /** kinds that add to the pay; everything else is taken off */
    public const EARNINGS = ['basic', 'allowance', 'overtime'];

    protected $fillable = ['payslip_id', 'kind', 'label', 'amount', 'loan_id'];

    protected function casts(): array
    {
        return ['amount' => 'float'];
    }

    public function isEarning(): bool
    {
        return in_array($this->kind, self::EARNINGS, true);
    }
}
