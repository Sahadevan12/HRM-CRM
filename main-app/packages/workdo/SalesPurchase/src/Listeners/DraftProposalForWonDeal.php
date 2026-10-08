<?php

namespace Workdo\SalesPurchase\Listeners;

use App\Events\DealWon;
use Workdo\ProductService\Models\Product;
use Workdo\ProductService\Models\Warehouse;
use Workdo\SalesPurchase\Models\Document;
use Workdo\SalesPurchase\Services\DocumentCalculator;
use Workdo\SalesPurchase\Services\DocumentService;
use Workdo\SalesPurchase\Support\DocumentType;

/**
 * Optional automation (company setting `crmDraftProposalOnWin` = 1): when a CRM deal is won, a DRAFT sales proposal is made for the deal's
 * client from the deal's products (quantity 1, list price), in the company's first warehouse. Nothing is sent or posted: a person reviews the
 * draft. It skips quietly - and says why - when the deal has no client or no products, or a draft for it exists already.
 */
class DraftProposalForWonDeal
{
    public function __construct(private DocumentService $documents, private DocumentCalculator $calculator)
    {
    }

    public function handle(DealWon $event): void
    {
        $tenant = $event->tenantId;

        if ((tenantSettings($tenant)['crmDraftProposalOnWin'] ?? '0') !== '1' || !Module_is_active('SalesPurchase', $tenant)) {
            return;
        }

        $marker = "CRM deal #{$event->dealId}: {$event->dealName}";

        if (!$event->clientId) {
            $event->note = __('No proposal drafted: the deal has no client.');

            return;
        }

        $products = Product::where('created_by', $tenant)->whereIn('id', $event->productIds)->get();
        if ($products->isEmpty()) {
            $event->note = __('No proposal drafted: the deal has no products.');

            return;
        }

        $warehouse = Warehouse::where('created_by', $tenant)->orderBy('id')->first();
        if (!$warehouse) {
            $event->note = __('No proposal drafted: the company has no warehouse yet.');

            return;
        }

        if (Document::where('created_by', $tenant)->where('type', DocumentType::SALES_PROPOSAL)->where('notes', 'like', "CRM deal #{$event->dealId}:%")->exists()) {
            $event->note = __('A proposal was already drafted for this deal.');

            return;
        }

        $priced = $this->calculator->forItems(
            $products->map(fn ($p) => ['product_id' => $p->id, 'quantity' => 1, 'unit_price' => $p->sale_price])->all(),
            $tenant,
        );

        $document = $this->documents->create(DocumentType::SALES_PROPOSAL, [
            'party_id' => $event->clientId, 'warehouse_id' => $warehouse->id, 'doc_date' => now()->toDateString(), 'due_date' => null, 'notes' => $marker,
        ], $priced, $tenant, $event->actorId);

        $event->documentId = $document->id;
        $event->note = __('Draft sales proposal :number created.', ['number' => $document->number]);
    }
}
