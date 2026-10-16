<?php

namespace Modules\Account\Listeners;

use App\Events\LoanDisbursed;
use App\Events\LoanDisbursementCancelled;
use Modules\Account\Models\JournalEntry;
use Modules\Account\Services\AccountService;
use Modules\Account\Services\JournalService;

/** A loan to an employee: Dr Employee Loans & Advances, Cr Cash / Bank. Deleting the (unrepaid) loan removes the entry again. */
class BookLoanDisbursement
{
    public const REFERENCE = 'loan';

    public function __construct(private JournalService $journal)
    {
    }

    public function handle(LoanDisbursed|LoanDisbursementCancelled $event): void
    {
        if (!Module_is_active('Account', $event->tenantId)) {
            return;
        }

        if ($event instanceof LoanDisbursementCancelled) {
            JournalEntry::where('created_by', $event->tenantId)->where('reference_type', self::REFERENCE)->where('reference_id', $event->loanId)->delete();

            return;
        }

        $this->journal->record($event->tenantId, $event->actorId, $event->date, __('Loan to :employee: :title', ['employee' => $event->employeeName, 'title' => $event->title]), [
            ['code' => AccountService::EMPLOYEE_LOANS, 'debit' => $event->amount],
            ['code' => $event->method === 'cash' ? AccountService::CASH : AccountService::BANK, 'credit' => $event->amount],
        ], self::REFERENCE, $event->loanId);
    }
}
