<?php

namespace Workdo\Account\Listeners;

use App\Events\PosPaymentReceived;
use Workdo\Account\Exceptions\AccountingException;
use Workdo\Account\Models\Payment;
use Workdo\Account\Services\AccountService;
use Workdo\Account\Services\PaymentService;
use Workdo\SalesPurchase\Exceptions\DocumentStateException;

/**
 * Books the money taken at the POS counter as a normal customer payment (Dr Cash or Bank, Cr Receivable) so the
 * invoice's paid_amount, the ledger and the customer statement all stay consistent.
 * Runs inside the POS sale transaction: if it fails the whole sale is rolled back.
 */
class RecordPosPayment
{
    public function __construct(private PaymentService $payments, private AccountService $accounts)
    {
    }

    public function handle(PosPaymentReceived $event): void
    {
        $document = $event->document;

        if (!Module_is_active('Account', $document->created_by)) {
            return;
        }

        // cash goes to the cash account, cards and bank transfers to the bank account
        $code = $event->method === 'cash' ? AccountService::CASH : AccountService::BANK;
        $account = $this->accounts->account($document->created_by, $code);

        try {
            $this->payments->create(Payment::CUSTOMER, $document, $account->id, $event->amount, $document->doc_date->toDateString(), 'POS', null, $event->cashierId);
        } catch (AccountingException $e) {
            throw new DocumentStateException($e->getMessage(), 0, $e);
        }

        $event->handled = true;
    }
}
