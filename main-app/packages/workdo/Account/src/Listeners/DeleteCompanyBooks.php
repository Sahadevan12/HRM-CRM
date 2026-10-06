<?php

namespace Workdo\Account\Listeners;

use App\Events\CompanyDeleting;
use Workdo\Account\Models\JournalEntry;
use Workdo\Account\Models\Payment;

/**
 * Payments (and journal lines) reference documents / users / accounts with RESTRICT foreign keys.
 * When a whole company is deleted its payments go first so the cascade is not blocked.
 */
class DeleteCompanyBooks
{
    public function handle(CompanyDeleting $event): void
    {
        $tenant = $event->company->id;

        Payment::where('created_by', $tenant)->delete();
        JournalEntry::where('created_by', $tenant)->delete(); // lines cascade; accounts are RESTRICTed by them
    }
}
