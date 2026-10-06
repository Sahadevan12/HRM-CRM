<?php

namespace Workdo\Account\Services;

use Illuminate\Support\Facades\DB;
use Workdo\Account\Exceptions\AccountingException;
use Workdo\Account\Models\JournalEntry;
use Workdo\Account\Models\Payment;
use Workdo\SalesPurchase\Models\Document;
use Workdo\SalesPurchase\Support\DocumentType;

/**
 * Customer / vendor payments against posted invoices.
 *   customer payment:  Dr Bank/Cash, Cr Accounts Receivable
 *   vendor payment:    Dr Accounts Payable, Cr Bank/Cash
 * and the invoice's paid_amount follows the payments.
 */
class PaymentService
{
    private const INVOICE_OF = [Payment::CUSTOMER => DocumentType::SALES_INVOICE, Payment::VENDOR => DocumentType::PURCHASE_INVOICE];

    public function __construct(private JournalService $journal)
    {
    }

    public static function invoiceType(string $kind): string
    {
        return self::INVOICE_OF[$kind];
    }

    /** What the party still owes / we still owe: total - paid - approved returns. */
    public function outstanding(Document $invoice): float
    {
        $returned = $invoice->children()
            ->whereIn('type', [DocumentType::SALES_RETURN, DocumentType::PURCHASE_RETURN])
            ->whereIn('status', ['approved', 'completed'])
            ->sum('total_amount');

        return round($invoice->total_amount - $invoice->paid_amount - (float) $returned, 2);
    }

    /**
     * @throws AccountingException when the payment is not allowed (message is user facing)
     */
    public function create(string $kind, Document $invoice, int $accountId, float $amount, string $date, ?string $reference, ?string $notes, ?int $actorId): Payment
    {
        return DB::transaction(function () use ($kind, $invoice, $accountId, $amount, $date, $reference, $notes, $actorId) {
            // lock the invoice so two simultaneous payments cannot both use the same outstanding amount
            $invoice = Document::whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($invoice->type !== self::invoiceType($kind) || $invoice->status !== 'posted') {
                throw new AccountingException(__('Only posted invoices can be paid.'));
            }

            $amount = round($amount, 2);
            $outstanding = $this->outstanding($invoice);
            if ($amount <= 0 || $amount > $outstanding + 0.005) {
                throw new AccountingException(__('The amount must be between 0.01 and the outstanding :amount.', ['amount' => $outstanding]));
            }

            $payment = Payment::create([
                'kind' => $kind, 'party_id' => $invoice->party_id, 'document_id' => $invoice->id, 'account_id' => $accountId,
                'payment_date' => $date, 'amount' => $amount, 'reference' => $reference, 'notes' => $notes,
                'creator_id' => $actorId, 'created_by' => $invoice->created_by,
            ]);

            $control = $kind === Payment::CUSTOMER ? AccountService::RECEIVABLE : AccountService::PAYABLE;
            $label = $kind === Payment::CUSTOMER ? __('Payment received for :n', ['n' => $invoice->number]) : __('Payment made for :n', ['n' => $invoice->number]);

            $this->journal->record($invoice->created_by, $actorId, $date, $label, [
                $kind === Payment::CUSTOMER
                    ? ['account_id' => $accountId, 'debit' => $amount]
                    : ['code' => $control, 'debit' => $amount],
                $kind === Payment::CUSTOMER
                    ? ['code' => $control, 'credit' => $amount]
                    : ['account_id' => $accountId, 'credit' => $amount],
            ], $kind . '_payment', $payment->id);

            $invoice->update(['paid_amount' => round($invoice->paid_amount + $amount, 2)]);

            return $payment;
        });
    }

    /** Undo a payment: its journal entry goes, the invoice becomes payable again. */
    public function delete(Payment $payment): void
    {
        DB::transaction(function () use ($payment) {
            $invoice = Document::whereKey($payment->document_id)->lockForUpdate()->firstOrFail();

            JournalEntry::where('created_by', $payment->created_by)
                ->where('reference_type', $payment->kind . '_payment')->where('reference_id', $payment->id)->delete();

            $invoice->update(['paid_amount' => max(0, round($invoice->paid_amount - $payment->amount, 2))]);
            $payment->delete();
        });
    }
}
