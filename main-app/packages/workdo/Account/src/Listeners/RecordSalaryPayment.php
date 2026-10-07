<?php

namespace Workdo\Account\Listeners;

use App\Events\PaySalary;
use Workdo\Account\Services\AccountService;
use Workdo\Account\Services\JournalService;

/**
 * A paid payroll becomes ONE journal entry:  Dr Salaries & Wages (expense)
 *                                           Cr Cash or Bank (net pay)
 *                                           Cr Salary Deductions Payable (deductions owed to third parties)
 *                                           Cr Employee Loans & Advances (instalments recovered)
 * Runs inside the pay transaction, so a failure leaves the payroll unpaid. Companies without the Account module simply skip it.
 */
class RecordSalaryPayment
{
    public function __construct(private JournalService $journal)
    {
    }

    public function handle(PaySalary $event): void
    {
        if (!Module_is_active('Account', $event->tenantId)) {
            return;
        }

        $this->journal->record($event->tenantId, $event->actorId, $event->date, __('Salaries of :month', ['month' => $event->month]), [
            ['code' => AccountService::SALARY_EXPENSE, 'debit' => $event->expense],
            ['code' => $event->method === 'cash' ? AccountService::CASH : AccountService::BANK, 'credit' => $event->net],
            ['code' => AccountService::SALARY_DEDUCTIONS, 'credit' => $event->deductions],
            ['code' => AccountService::EMPLOYEE_LOANS, 'credit' => $event->loans],
        ], 'payroll', $event->payrollId);

        $event->handled = true;
    }
}
