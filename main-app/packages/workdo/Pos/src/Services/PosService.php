<?php

namespace Workdo\Pos\Services;

use App\Events\PosPaymentReceived;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Workdo\Pos\Models\PosSale;
use Workdo\Pos\Support\WalkInCustomer;
use Workdo\ProductService\Models\Product;
use Workdo\SalesPurchase\Models\Document;
use Workdo\SalesPurchase\Services\DocumentCalculator;
use Workdo\SalesPurchase\Services\DocumentService;
use Workdo\SalesPurchase\Support\DocumentType;

/**
 * A counter sale in one atomic step:
 *   price the cart (prices and taxes come from the DB, never from the browser)
 *   -> create a sales invoice -> post it (stock out + ledger via the normal events)
 *   -> take the payment (customer payment in Accounting, or paid_amount when Accounting is not used).
 * Any failure (not enough stock, ledger error...) rolls the whole sale back.
 */
class PosService
{
    public function __construct(private DocumentCalculator $calculator, private DocumentService $documents)
    {
    }

    /**
     * @param  array{warehouse_id: int, customer_id?: ?int, payment_method: string, amount_tendered?: float|int|string|null, items: array<int, array{product_id: int, quantity: float|int|string, discount_amount?: float|int|string|null}>}  $data
     *
     * @throws ValidationException
     */
    public function checkout(int $tenantId, ?int $cashierId, array $data): PosSale
    {
        $method = $data['payment_method'];
        $customerId = $data['customer_id'] ?? null;

        if ($method === 'credit' && (!$customerId || $customerId === WalkInCustomer::id($tenantId))) {
            throw ValidationException::withMessages(['customer_id' => __('A sale on account needs a customer.')]);
        }

        // prices + taxes are taken from the product, the cashier can only choose quantity and a discount
        $products = Product::where('created_by', $tenantId)->whereIn('id', array_column($data['items'], 'product_id'))->get()->keyBy('id');
        $lines = [];
        foreach ($data['items'] as $item) {
            $product = $products[$item['product_id']];
            $lines[] = [
                'product_id' => $product->id,
                'quantity' => $item['quantity'],
                'unit_price' => $product->sale_price,
                'discount_amount' => $item['discount_amount'] ?? 0,
                'tax_ids' => $product->tax_ids ?? [],
            ];
        }

        $priced = $this->calculator->forItems($lines, $tenantId);
        $total = $priced['totals']['total_amount'];

        [$paid, $tendered, $change] = $this->settle($method, $total, (float) ($data['amount_tendered'] ?? 0));

        return DB::transaction(function () use ($tenantId, $cashierId, $data, $method, $customerId, $priced, $paid, $tendered, $change) {
            $document = $this->documents->create(DocumentType::SALES_INVOICE, [
                'party_id' => $customerId ?: WalkInCustomer::id($tenantId),
                'warehouse_id' => $data['warehouse_id'],
                'doc_date' => now()->toDateString(),
                'notes' => __('POS sale'),
            ], $priced, $tenantId, $cashierId);

            $this->documents->post($document); // stock out + ledger

            $sale = PosSale::create([
                'document_id' => $document->id, 'cashier_id' => $cashierId, 'payment_method' => $method,
                'amount_paid' => $paid, 'amount_tendered' => $tendered, 'change_due' => $change, 'created_by' => $tenantId,
            ]);

            if ($paid > 0) {
                $this->receivePayment($document->refresh(), $method, $paid, $cashierId);
            }

            return $sale->load('document.items.taxes', 'document.party:id,name', 'cashier:id,name');
        });
    }

    /**
     * What the counter keeps. Cash may be handed over in a bigger note (change is given back); cards and transfers pay
     * exactly the total; a sale on account is not paid now.
     *
     * @return array{0: float, 1: float, 2: float} paid, tendered, change
     */
    private function settle(string $method, float $total, float $tendered): array
    {
        if ($method === 'credit') {
            return [0.0, 0.0, 0.0];
        }

        if ($method === 'cash') {
            if (round($tendered, 2) < $total) {
                throw ValidationException::withMessages(['amount_tendered' => __('The cash received is less than the total :total.', ['total' => $total])]);
            }

            return [$total, round($tendered, 2), round($tendered - $total, 2)];
        }

        return [$total, $total, 0.0];
    }

    private function receivePayment(Document $document, string $method, float $amount, ?int $cashierId): void
    {
        $event = new PosPaymentReceived($document, $method, $amount, $cashierId);
        event($event); // Accounting (if the company has it) books the customer payment and sets $event->handled

        if (!$event->handled) {
            $document->update(['paid_amount' => $amount]); // no ledger: the invoice is simply marked as paid
        }
    }
}
