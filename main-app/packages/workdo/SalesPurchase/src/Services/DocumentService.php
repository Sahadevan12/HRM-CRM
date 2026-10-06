<?php

namespace Workdo\SalesPurchase\Services;

use App\Events\ApprovePurchaseReturn;
use App\Events\ApproveSalesReturn;
use App\Events\CompletePurchaseReturn;
use App\Events\CompleteSalesReturn;
use App\Events\ConvertSalesProposal;
use App\Events\PostPurchaseInvoice;
use App\Events\PostSalesInvoice;
use Illuminate\Support\Facades\DB;
use Workdo\ProductService\Services\StockService;
use Workdo\SalesPurchase\Exceptions\DocumentStateException;
use Workdo\SalesPurchase\Models\Document;
use Workdo\SalesPurchase\Support\DocumentType;

/**
 * Lifecycle of the trade documents:
 *
 *   sales/purchase invoice : draft --post--> posted            (stock moves on post, then the document is immutable)
 *   sales proposal         : draft -> sent -> accepted -> converted (creates a draft sales invoice) | rejected
 *   sales/purchase return  : draft --approve--> approved (stock moves) --complete--> completed
 *
 * Only draft documents can be edited or deleted. Corrections to posted documents are done with returns.
 */
class DocumentService
{
    public function __construct(private StockService $stock, private DocumentCalculator $calculator)
    {
    }

    // ───────────────────────── create / edit / delete ─────────────────────────

    /**
     * @param  array<string, mixed>  $header  party_id, warehouse_id, doc_date, due_date?, notes?, reason?, parent_id?
     * @param  array{lines: array, totals: array}  $priced  result of DocumentCalculator
     */
    public function create(string $type, array $header, array $priced, int $tenantId, ?int $actorId): Document
    {
        return DB::transaction(function () use ($type, $header, $priced, $tenantId, $actorId) {
            $document = Document::create($header + $priced['totals'] + [
                'type' => $type,
                'number' => $this->nextNumber($type, $tenantId),
                'status' => 'draft',
                'creator_id' => $actorId,
                'created_by' => $tenantId,
            ]);

            $this->writeLines($document, $priced['lines']);

            return $document->load('items.taxes');
        });
    }

    /** Replace header + lines of a draft. */
    public function update(Document $document, array $header, array $priced): Document
    {
        $this->assertStatus($document, ['draft'], 'Only draft documents can be edited.');

        return DB::transaction(function () use ($document, $header, $priced) {
            $document->update($header + $priced['totals']);
            $document->items()->delete(); // item taxes cascade
            $this->writeLines($document, $priced['lines']);

            return $document->load('items.taxes');
        });
    }

    public function delete(Document $document): void
    {
        $this->assertStatus($document, ['draft'], 'Only draft documents can be deleted.');

        DB::transaction(function () use ($document) {
            // a draft invoice that came from a proposal gives the proposal back
            if ($document->type === DocumentType::SALES_INVOICE && $document->parent_id) {
                Document::where('id', $document->parent_id)->where('type', DocumentType::SALES_PROPOSAL)->where('status', 'converted')
                    ->update(['status' => 'accepted']);
            }

            $document->delete();
        });
    }

    // ───────────────────────── invoices ─────────────────────────

    /** Post a draft invoice: moves stock (out for sales, in for purchases) and freezes the document. */
    public function post(Document $document): Document
    {
        if (!in_array($document->type, [DocumentType::SALES_INVOICE, DocumentType::PURCHASE_INVOICE], true)) {
            throw new DocumentStateException(__('This document cannot be posted.'));
        }
        $this->assertStatus($document, ['draft'], 'Only draft invoices can be posted.');
        $this->assertHasItems($document);

        DB::transaction(function () use ($document) {
            $this->moveStock($document, DocumentType::get($document->type)['stock']);
            $document->update(['status' => 'posted', 'posted_at' => now()]);

            // dispatched INSIDE the transaction: listeners (e.g. the ledger) are part of the posting, a failure rolls everything back
            $document->type === DocumentType::SALES_INVOICE
                ? PostSalesInvoice::dispatch($document)
                : PostPurchaseInvoice::dispatch($document);
        });

        return $document;
    }

    // ───────────────────────── proposals ─────────────────────────

    public function sendProposal(Document $proposal): Document
    {
        $this->assertType($proposal, DocumentType::SALES_PROPOSAL);
        $this->assertStatus($proposal, ['draft'], 'Only draft proposals can be sent.');
        $this->assertHasItems($proposal);
        $proposal->update(['status' => 'sent']);

        return $proposal;
    }

    public function answerProposal(Document $proposal, bool $accepted): Document
    {
        $this->assertType($proposal, DocumentType::SALES_PROPOSAL);
        $this->assertStatus($proposal, ['sent'], 'Only sent proposals can be accepted or rejected.');
        $proposal->update(['status' => $accepted ? 'accepted' : 'rejected']);

        return $proposal;
    }

    /** Accepted proposal -> new draft sales invoice with copied lines. */
    public function convertProposal(Document $proposal, ?int $actorId): Document
    {
        $this->assertType($proposal, DocumentType::SALES_PROPOSAL);
        $this->assertStatus($proposal, ['accepted'], 'Only accepted proposals can be converted.');

        $invoice = DB::transaction(function () use ($proposal, $actorId) {
            $proposal->load('items.taxes');

            $invoice = Document::create([
                'type' => DocumentType::SALES_INVOICE,
                'number' => $this->nextNumber(DocumentType::SALES_INVOICE, $proposal->created_by),
                'party_id' => $proposal->party_id,
                'warehouse_id' => $proposal->warehouse_id,
                'parent_id' => $proposal->id,
                'doc_date' => now()->toDateString(),
                'status' => 'draft',
                'subtotal' => $proposal->subtotal,
                'discount_amount' => $proposal->discount_amount,
                'tax_amount' => $proposal->tax_amount,
                'total_amount' => $proposal->total_amount,
                'notes' => $proposal->notes,
                'creator_id' => $actorId,
                'created_by' => $proposal->created_by,
            ]);

            foreach ($proposal->items as $item) {
                $copy = $invoice->items()->create($item->only(['product_id', 'name', 'quantity', 'unit_price', 'discount_amount', 'tax_amount', 'total_amount']));
                foreach ($item->taxes as $tax) {
                    $copy->taxes()->create($tax->only(['tax_id', 'name', 'rate', 'amount']));
                }
            }

            $proposal->update(['status' => 'converted']);

            return $invoice;
        });

        ConvertSalesProposal::dispatch($proposal);

        return $invoice;
    }

    // ───────────────────────── returns ─────────────────────────

    /** Approve a draft return: stock comes back (sales return) or leaves again (purchase return). */
    public function approveReturn(Document $return): Document
    {
        $this->assertReturn($return);
        $this->assertStatus($return, ['draft'], 'Only draft returns can be approved.');
        $this->assertHasItems($return);

        DB::transaction(function () use ($return) {
            $this->moveStock($return, DocumentType::get($return->type)['stock']);
            $return->update(['status' => 'approved', 'posted_at' => now()]);

            $return->type === DocumentType::SALES_RETURN
                ? ApproveSalesReturn::dispatch($return)
                : ApprovePurchaseReturn::dispatch($return);
        });

        return $return;
    }

    public function completeReturn(Document $return): Document
    {
        $this->assertReturn($return);
        $this->assertStatus($return, ['approved'], 'Only approved returns can be completed.');
        $return->update(['status' => 'completed']);

        $return->type === DocumentType::SALES_RETURN
            ? CompleteSalesReturn::dispatch($return)
            : CompletePurchaseReturn::dispatch($return);

        return $return;
    }

    // ───────────────────────── internals ─────────────────────────

    private function writeLines(Document $document, array $lines): void
    {
        foreach ($lines as $line) {
            $item = $document->items()->create([
                'product_id' => $line['product_id'],
                'source_item_id' => $line['source_item_id'] ?? null,
                'name' => $line['name'],
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'discount_amount' => $line['discount_amount'],
                'tax_amount' => $line['tax_amount'],
                'total_amount' => $line['total_amount'],
            ]);

            foreach ($line['taxes'] as $tax) {
                $item->taxes()->create($tax);
            }
        }
    }

    /** Move stock for every physical product line ('out' = leaves the warehouse, 'in' = enters). Services are skipped. */
    private function moveStock(Document $document, ?string $direction): void
    {
        if ($direction === null) {
            return;
        }

        foreach ($document->items()->with('product')->get() as $item) {
            if ($item->product->isService()) {
                continue;
            }

            $this->stock->adjust($item->product, $document->warehouse_id, $direction === 'out' ? -$item->quantity : $item->quantity);
        }
    }

    /** Next running number for a tenant + type, e.g. SI-00007. The unique index is the final guard against races. */
    private function nextNumber(string $type, int $tenantId): string
    {
        $prefix = DocumentType::get($type)['prefix'];

        $last = Document::where('created_by', $tenantId)->where('type', $type)->orderByDesc('id')->lockForUpdate()->value('number');
        $next = $last ? ((int) substr($last, strlen($prefix) + 1)) + 1 : 1;

        return sprintf('%s-%05d', $prefix, $next);
    }

    private function assertStatus(Document $document, array $allowed, string $message): void
    {
        if (!in_array($document->status, $allowed, true)) {
            throw new DocumentStateException(__($message));
        }
    }

    private function assertType(Document $document, string $type): void
    {
        if ($document->type !== $type) {
            throw new DocumentStateException(__('This action is not available for this document.'));
        }
    }

    private function assertReturn(Document $document): void
    {
        if (!DocumentType::isReturn($document->type)) {
            throw new DocumentStateException(__('This action is not available for this document.'));
        }
    }

    private function assertHasItems(Document $document): void
    {
        if (!$document->items()->exists()) {
            throw new DocumentStateException(__('The document has no lines.'));
        }
    }
}
