<?php

namespace Workdo\Account\Listeners;

use App\Events\TradeDocumentEvent;
use Workdo\Account\Exceptions\AccountingException;
use Workdo\Account\Services\JournalService;
use Workdo\SalesPurchase\Exceptions\DocumentStateException;

/**
 * Books posted invoices and approved returns into the general ledger.
 *
 * It runs inside the transaction that posts/approves the document (the events are dispatched there), so a failure
 * rolls the whole posting back: the books and the stock can never disagree.
 * Companies whose plan does not include Accounting are skipped.
 */
class PostDocumentToLedger
{
    public function __construct(private JournalService $journal)
    {
    }

    public function handle(TradeDocumentEvent $event): void
    {
        $document = $event->document;

        if (!Module_is_active('Account', $document->created_by)) {
            return;
        }

        try {
            $this->journal->postDocument($document);
        } catch (AccountingException $e) {
            // surfaces as a normal 'cannot post' message and rolls the posting back
            throw new DocumentStateException($e->getMessage(), 0, $e);
        }
    }
}
