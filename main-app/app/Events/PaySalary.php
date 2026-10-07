<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A payroll was paid out. Accounting listens and books it
 *   Dr Salary Expense (expense), Cr Cash/Bank (net), Cr Salary Deductions Payable (deductions), Cr Employee Loans (loans).
 * Plain numbers only, so HRM and Accounting stay independent modules. Dispatched inside the pay transaction.
 *
 *   expense    = gross earnings of the whole payroll
 *   net        = what leaves cash / bank
 *   deductions = withheld deductions owed to third parties
 *   loans      = loan instalments recovered from the staff
 * (an unpaid day simply lowers the expense, it is not booked separately)
 */
class PaySalary
{
    use Dispatchable;

    public bool $handled = false;

    public function __construct(
        public int $tenantId,
        public int $payrollId,
        public string $month,
        public string $date,
        public float $expense,
        public float $net,
        public float $deductions,
        public float $loans,
        public string $method,
        public ?int $actorId,
    ) {
    }
}
