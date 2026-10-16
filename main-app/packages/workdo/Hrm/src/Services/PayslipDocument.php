<?php

namespace Workdo\Hrm\Services;

use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;
use Workdo\Hrm\Models\Payslip;
use Workdo\Hrm\Models\PayslipLine;

/** The payslip as a PDF (web download and mobile API share it). The caller has already checked who may read the payslip. */
class PayslipDocument
{
    public function render(Payslip $payslip): PdfDocument
    {
        $payslip->loadMissing('payroll', 'employee.user:id,name', 'employee.designation:id,name', 'employee.department:id,name', 'lines');
        $tenant = $payslip->payroll->created_by;
        $settings = tenantSettings($tenant);

        $money = function (float $amount) use ($settings) {
            $symbol = $settings['currencySymbol'] ?? '$';
            $number = number_format($amount, (int) ($settings['currencyDecimals'] ?? 2));

            return ($settings['currencyPosition'] ?? 'before') === 'after' ? $number . $symbol : $symbol . $number;
        };

        return Pdf::loadView('hrm::payslip', [
            'payslip' => $payslip,
            'company' => $settings['company_name'] ?? User::whereKey($tenant)->value('name'),
            'earnings' => $payslip->lines->filter(fn (PayslipLine $l) => $l->isEarning())->values(),
            'deductions' => $payslip->lines->reject(fn (PayslipLine $l) => $l->isEarning())->values(),
            'money' => $money,
        ])->setPaper('a4')->setOption('defaultFont', 'DejaVu Sans'); // DejaVu has the currency symbols
    }

    public function filename(Payslip $payslip): string
    {
        $payslip->loadMissing('payroll', 'employee');

        return "payslip-{$payslip->employee->employee_code}-{$payslip->payroll->month}.pdf";
    }
}
