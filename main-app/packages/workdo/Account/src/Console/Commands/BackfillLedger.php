<?php

namespace Workdo\Account\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Workdo\Account\Services\JournalService;
use Workdo\SalesPurchase\Models\Document;
use Workdo\SalesPurchase\Support\DocumentType;

/**
 * Books posted invoices / approved returns that have no journal entry yet, for companies that got the Accounting module
 * after they had already started trading. Safe to run any number of times (an entry exists once per document).
 */
class BackfillLedger extends Command
{
    protected $signature = 'account:backfill {company? : Company (user) id. Default: every company with the Accounting module}';

    protected $description = 'Book already posted trade documents into the general ledger';

    public function handle(JournalService $journal): int
    {
        $companies = User::where('type', 'company')
            ->when($this->argument('company'), fn ($q, $id) => $q->whereKey($id))
            ->get()
            ->filter(fn (User $c) => Module_is_active('Account', $c->id));

        $total = 0;
        foreach ($companies as $company) {
            $documents = Document::where('created_by', $company->id)
                ->where(fn ($q) => $q
                    ->whereIn('type', [DocumentType::SALES_INVOICE, DocumentType::PURCHASE_INVOICE])->where('status', 'posted')
                    ->orWhereIn('type', [DocumentType::SALES_RETURN, DocumentType::PURCHASE_RETURN])->whereIn('status', ['approved', 'completed']))
                ->orderBy('doc_date')->orderBy('id')->get();

            foreach ($documents as $document) {
                $journal->postDocument($document);
                $total++;
            }

            $this->line("{$company->name}: {$documents->count()} documents checked");
        }

        $this->info("Done. {$total} documents are booked.");

        return self::SUCCESS;
    }
}
