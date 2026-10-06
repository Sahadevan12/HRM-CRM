<?php

namespace Workdo\Account\Services;

use Illuminate\Support\Facades\DB;
use Workdo\Account\Exceptions\UnbalancedJournalException;
use Workdo\Account\Models\ChartOfAccount;
use Workdo\Account\Models\JournalEntry;
use Workdo\SalesPurchase\Models\Document;
use Workdo\SalesPurchase\Support\DocumentType;

/**
 * Double-entry bookkeeping. Every change of the books goes through record(), which refuses anything that is
 * not balanced. Balances are never stored: they are always summed from the journal lines, so they cannot drift.
 */
class JournalService
{
    private const TOLERANCE = 0.005;

    public function __construct(private AccountService $accounts)
    {
    }

    /**
     * @param  array<int, array{account_id?: int, code?: string, debit?: float|int|string, credit?: float|int|string, description?: string}>  $lines
     *
     * @throws UnbalancedJournalException
     */
    public function record(
        int $tenantId,
        ?int $actorId,
        string $date,
        string $description,
        array $lines,
        ?string $referenceType = null,
        ?int $referenceId = null,
        string $entryType = 'automatic',
    ): JournalEntry {
        $this->accounts->ensureDefaults($tenantId);

        // an automatic entry exists once per source document, re-posting is a no-op
        if ($referenceType !== null && $referenceId !== null) {
            $existing = JournalEntry::where('created_by', $tenantId)->where('reference_type', $referenceType)->where('reference_id', $referenceId)->first();
            if ($existing) {
                return $existing;
            }
        }

        $prepared = $this->prepare($tenantId, $lines);

        $debit = round(array_sum(array_column($prepared, 'debit')), 2);
        $credit = round(array_sum(array_column($prepared, 'credit')), 2);

        if (count($prepared) < 2) {
            throw new UnbalancedJournalException(__('A journal entry needs at least two lines.'));
        }
        if (abs($debit - $credit) > self::TOLERANCE) {
            throw new UnbalancedJournalException(__('Journal entry not balanced: debit :debit, credit :credit.', ['debit' => $debit, 'credit' => $credit]));
        }

        return DB::transaction(function () use ($tenantId, $actorId, $date, $description, $prepared, $debit, $credit, $referenceType, $referenceId, $entryType) {
            $entry = JournalEntry::create([
                'number' => $this->nextNumber($tenantId),
                'journal_date' => $date,
                'entry_type' => $entryType,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'description' => $description,
                'total_debit' => $debit,
                'total_credit' => $credit,
                'creator_id' => $actorId,
                'created_by' => $tenantId,
            ]);

            foreach ($prepared as $line) {
                $entry->items()->create($line);
            }

            return $entry->load('items.account');
        });
    }

    /** Book an approved/posted trade document (see the rules in documentLines()). */
    public function postDocument(Document $document): JournalEntry
    {
        $document->loadMissing('items.product');

        return $this->record(
            $document->created_by,
            $document->creator_id,
            $document->doc_date->toDateString(), // booked on the document date (invoice date), not on the day somebody pressed Post
            __(DocumentType::get($document->type)['label']) . ' ' . $document->number,
            $this->documentLines($document),
            $document->type,
            $document->id,
        );
    }

    /**
     * Posting rules ("net" = subtotal - discount):
     *   sales invoice    Dr Receivable total            | Cr Sales net, Cr Tax Payable tax      + Dr COGS / Cr Inventory at purchase price
     *   sales return     Dr Sales Returns net, Dr Tax Payable tax | Cr Receivable total          + Dr Inventory / Cr COGS
     *   purchase invoice Dr Inventory (products) / Services expense (services), Dr Tax Receivable | Cr Payable total
     *   purchase return  the exact opposite of the purchase invoice
     *
     * @return array<int, array<string, mixed>>
     */
    public function documentLines(Document $document): array
    {
        $net = round($document->subtotal - $document->discount_amount, 2);
        $tax = $document->tax_amount;
        $total = $document->total_amount;
        $cost = $this->costOfGoods($document);

        $lines = match ($document->type) {
            DocumentType::SALES_INVOICE => [
                ['code' => AccountService::RECEIVABLE, 'debit' => $total, 'description' => __('Sales to :name', ['name' => $document->party->name ?? ''])],
                ['code' => AccountService::SALES, 'credit' => $net, 'description' => __('Sales')],
                ['code' => AccountService::TAX_PAYABLE, 'credit' => $tax, 'description' => __('Sales tax collected')],
                ['code' => AccountService::COGS, 'debit' => $cost, 'description' => __('Cost of goods sold')],
                ['code' => AccountService::INVENTORY, 'credit' => $cost, 'description' => __('Inventory issued')],
            ],
            DocumentType::SALES_RETURN => [
                ['code' => AccountService::SALES_RETURNS, 'debit' => $net, 'description' => __('Sales return')],
                ['code' => AccountService::TAX_PAYABLE, 'debit' => $tax, 'description' => __('Sales tax reversed')],
                ['code' => AccountService::RECEIVABLE, 'credit' => $total, 'description' => __('Credit to customer')],
                ['code' => AccountService::INVENTORY, 'debit' => $cost, 'description' => __('Inventory returned')],
                ['code' => AccountService::COGS, 'credit' => $cost, 'description' => __('Cost of goods sold reversed')],
            ],
            DocumentType::PURCHASE_INVOICE, DocumentType::PURCHASE_RETURN => $this->purchaseLines($document, $tax, $total),
            default => throw new UnbalancedJournalException(__('This document type is not booked.')),
        };

        return $lines;
    }

    /** @return array<int, array<string, mixed>> */
    private function purchaseLines(Document $document, float $tax, float $total): array
    {
        $inventory = 0.0;
        $services = 0.0;
        foreach ($document->items as $item) {
            $net = round($item->quantity * $item->unit_price - $item->discount_amount, 2);
            $item->product?->isService() ? $services += $net : $inventory += $net;
        }

        $return = $document->type === DocumentType::PURCHASE_RETURN;
        $side = fn (float $amount, bool $debitOnInvoice) => ($debitOnInvoice xor $return) ? ['debit' => $amount] : ['credit' => $amount];
        $label = $return ? __('Purchase return') : __('Purchase');

        return [
            ['code' => AccountService::INVENTORY, 'description' => $label] + $side(round($inventory, 2), true),
            ['code' => AccountService::SERVICE_EXPENSE, 'description' => $label] + $side(round($services, 2), true),
            ['code' => AccountService::TAX_RECEIVABLE, 'description' => __('Input tax')] + $side($tax, true),
            ['code' => AccountService::PAYABLE, 'description' => __('Payable to :name', ['name' => $document->party->name ?? ''])] + $side($total, false),
        ];
    }

    /** Purchase price of the physical products on a sales document (services have no cost). */
    private function costOfGoods(Document $document): float
    {
        if (!in_array($document->type, [DocumentType::SALES_INVOICE, DocumentType::SALES_RETURN], true)) {
            return 0.0;
        }

        return round($document->items->sum(fn ($item) => $item->product && !$item->product->isService() ? $item->quantity * $item->product->purchase_price : 0), 2);
    }

    /**
     * Resolve accounts, drop empty lines and validate.
     *
     * @return array<int, array{account_id: int, description: ?string, debit: float, credit: float}>
     */
    private function prepare(int $tenantId, array $lines): array
    {
        $byCode = ChartOfAccount::where('created_by', $tenantId)->pluck('id', 'code');
        $active = ChartOfAccount::where('created_by', $tenantId)->where('is_active', true)->pluck('id')->flip();

        $prepared = [];
        foreach ($lines as $line) {
            $debit = round((float) ($line['debit'] ?? 0), 2);
            $credit = round((float) ($line['credit'] ?? 0), 2);

            if ($debit < 0 || $credit < 0 || ($debit > 0 && $credit > 0)) {
                throw new UnbalancedJournalException(__('Each line needs either a debit or a credit amount.'));
            }
            if ($debit == 0.0 && $credit == 0.0) {
                continue;
            }

            $accountId = $line['account_id'] ?? ($byCode[$line['code'] ?? ''] ?? null);
            if (!$accountId || !isset($active[$accountId])) {
                throw new UnbalancedJournalException(__('Unknown or inactive account.'));
            }

            $prepared[] = ['account_id' => (int) $accountId, 'description' => $line['description'] ?? null, 'debit' => $debit, 'credit' => $credit];
        }

        return $prepared;
    }

    /** JE-00001 ... per company; the unique index is the last guard against two requests racing. */
    private function nextNumber(int $tenantId): string
    {
        $last = JournalEntry::where('created_by', $tenantId)->orderByDesc('id')->lockForUpdate()->value('number');

        return sprintf('JE-%05d', $last ? ((int) substr($last, 3)) + 1 : 1);
    }
}
