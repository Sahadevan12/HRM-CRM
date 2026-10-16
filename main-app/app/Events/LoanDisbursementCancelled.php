<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** A loan that had no repayments yet was deleted: accounting removes the entry it booked for the disbursement. */
class LoanDisbursementCancelled
{
    use Dispatchable;

    public function __construct(public int $tenantId, public int $loanId)
    {
    }
}
