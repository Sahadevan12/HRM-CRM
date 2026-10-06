<?php

namespace Workdo\SalesPurchase\Services;

use Illuminate\Validation\ValidationException;
use Workdo\ProductService\Models\Product;
use Workdo\ProductService\Models\ProductTax;
use Workdo\SalesPurchase\Models\Document;
use Workdo\SalesPurchase\Models\DocumentItem;

/**
 * Turns raw line input into priced lines + totals. All money maths lives here (server side, never trusted from the browser):
 *
 *   gross  = quantity * unit_price
 *   net    = gross - discount
 *   tax_i  = net * rate_i / 100          (each tax rounded to 2 decimals, per line)
 *   line   = net + sum(tax_i)
 *   totals : subtotal = sum(gross), discount = sum(discount), tax = sum(tax), total = subtotal - discount + tax
 */
class DocumentCalculator
{
    /**
     * @param  array<int, array<string, mixed>>  $items  [{product_id, quantity, unit_price, discount_amount?, tax_ids?}]
     * @return array{lines: array<int, array<string, mixed>>, totals: array<string, float>}
     */
    public function forItems(array $items, int $tenantId): array
    {
        $products = Product::where('created_by', $tenantId)->whereIn('id', array_column($items, 'product_id'))->get()->keyBy('id');
        $taxIds = collect($items)->pluck('tax_ids')->flatten()->filter()->unique()->all();
        $taxes = ProductTax::where('created_by', $tenantId)->whereIn('id', $taxIds)->get()->keyBy('id');

        $lines = [];
        foreach (array_values($items) as $i => $item) {
            $product = $products[$item['product_id']] ?? null;
            if (!$product) {
                throw ValidationException::withMessages(["items.{$i}.product_id" => __('Unknown product.')]);
            }

            $quantity = round((float) $item['quantity'], 2);
            $price = round((float) $item['unit_price'], 2);
            $discount = round((float) ($item['discount_amount'] ?? 0), 2);
            $gross = round($quantity * $price, 2);

            if ($discount > $gross) {
                throw ValidationException::withMessages(["items.{$i}.discount_amount" => __('The discount cannot exceed the line amount.')]);
            }

            $net = round($gross - $discount, 2);
            $lineTaxes = [];
            foreach (array_unique($item['tax_ids'] ?? []) as $taxId) {
                $tax = $taxes[$taxId] ?? throw ValidationException::withMessages(["items.{$i}.tax_ids" => __('Unknown tax.')]);
                $lineTaxes[] = ['tax_id' => $tax->id, 'name' => $tax->name, 'rate' => (float) $tax->rate, 'amount' => round($net * $tax->rate / 100, 2)];
            }

            $lines[] = $this->line($product->id, $product->name, $quantity, $price, $gross, $discount, $lineTaxes, null);
        }

        return ['lines' => $lines, 'totals' => $this->totals($lines)];
    }

    /**
     * Lines of a return, priced exactly like the invoice they come from (discount and taxes pro rata).
     *
     * @param  array<int, array{source_item_id: int, quantity: float|int|string}>  $items
     */
    public function forReturn(Document $invoice, array $items): array
    {
        $sources = $invoice->items()->with('taxes')->get()->keyBy('id');
        $remaining = $this->returnableQuantities($invoice);

        $lines = [];
        foreach (array_values($items) as $i => $item) {
            /** @var DocumentItem|null $source */
            $source = $sources[$item['source_item_id']] ?? null;
            if (!$source) {
                throw ValidationException::withMessages(["items.{$i}.source_item_id" => __('This line does not belong to the invoice.')]);
            }

            $quantity = round((float) $item['quantity'], 2);
            if ($quantity > ($remaining[$source->id] ?? 0)) {
                throw ValidationException::withMessages(["items.{$i}.quantity" => __('Only :qty can still be returned for :name.', ['qty' => $remaining[$source->id] ?? 0, 'name' => $source->name])]);
            }

            $ratio = $quantity / $source->quantity;
            $gross = round($quantity * $source->unit_price, 2);
            $discount = round($source->discount_amount * $ratio, 2);
            $lineTaxes = $source->taxes->map(fn ($t) => [
                'tax_id' => $t->tax_id, 'name' => $t->name, 'rate' => (float) $t->rate, 'amount' => round($t->amount * $ratio, 2),
            ])->all();

            $lines[] = $this->line($source->product_id, $source->name, $quantity, $source->unit_price, $gross, $discount, $lineTaxes, $source->id);
        }

        return ['lines' => $lines, 'totals' => $this->totals($lines)];
    }

    /**
     * Quantity of every invoice line that can still be returned (invoice qty - qty already on returns, rejected nothing).
     *
     * @return array<int, float> [invoice_item_id => remaining]
     */
    public function returnableQuantities(Document $invoice): array
    {
        $returned = DocumentItem::whereIn('document_id', $invoice->children()->whereIn('type', ['sales_return', 'purchase_return'])->select('id'))
            ->whereNotNull('source_item_id')
            ->selectRaw('source_item_id, SUM(quantity) as qty')
            ->groupBy('source_item_id')
            ->pluck('qty', 'source_item_id');

        return $invoice->items->mapWithKeys(fn ($item) => [$item->id => round($item->quantity - (float) ($returned[$item->id] ?? 0), 2)])->all();
    }

    private function line(int $productId, string $name, float $quantity, float $price, float $gross, float $discount, array $taxes, ?int $sourceItemId): array
    {
        $tax = round(array_sum(array_column($taxes, 'amount')), 2);

        return [
            'product_id' => $productId,
            'source_item_id' => $sourceItemId,
            'name' => $name,
            'quantity' => $quantity,
            'unit_price' => $price,
            'gross' => $gross,
            'discount_amount' => $discount,
            'tax_amount' => $tax,
            'total_amount' => round($gross - $discount + $tax, 2),
            'taxes' => $taxes,
        ];
    }

    /** @return array{subtotal: float, discount_amount: float, tax_amount: float, total_amount: float} */
    private function totals(array $lines): array
    {
        $subtotal = round(array_sum(array_column($lines, 'gross')), 2);
        $discount = round(array_sum(array_column($lines, 'discount_amount')), 2);
        $tax = round(array_sum(array_column($lines, 'tax_amount')), 2);

        return ['subtotal' => $subtotal, 'discount_amount' => $discount, 'tax_amount' => $tax, 'total_amount' => round($subtotal - $discount + $tax, 2)];
    }
}
