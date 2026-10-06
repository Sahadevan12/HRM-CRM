<?php

namespace Workdo\SalesPurchase\Listeners;

use App\Events\CompanyDeleting;
use Workdo\SalesPurchase\Models\Document;

/**
 * documents.party_id / warehouse_id / document_items.product_id use RESTRICT foreign keys (financial records must not
 * disappear when a customer, warehouse or product is deleted). When a whole company is deleted its documents go first,
 * otherwise the cascade over users/products/warehouses would be blocked.
 */
class DeleteCompanyDocuments
{
    public function handle(CompanyDeleting $event): void
    {
        // children (returns, invoices from proposals) reference parents with ON DELETE SET NULL, so order does not matter
        Document::where('created_by', $event->company->id)->get()->each->delete();
    }
}
