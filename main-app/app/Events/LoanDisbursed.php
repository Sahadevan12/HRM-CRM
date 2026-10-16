<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Money was lent to an employee. Accounting books it:  Dr Employee Loans & Advances, Cr Cash or Bank.
 * (The instalments recovered through payroll credit the same account, see PaySalary.) Plain data, dispatched inside the transaction.
 */
class LoanDisbursed
{
    use Dispatchable;

    public function __construct(
        public int $tenantId,
        public int $loanId,
        public string $title,
        public string $employeeName,
        public float $amount,
        public string $date,
        public string $method,
        public ?int $actorId,
    ) {
    }
}
