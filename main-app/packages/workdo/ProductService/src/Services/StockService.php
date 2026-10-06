<?php

namespace Workdo\ProductService\Services;

use Illuminate\Support\Facades\DB;
use Workdo\ProductService\Exceptions\InsufficientStockException;
use Workdo\ProductService\Models\Product;
use Workdo\ProductService\Models\ProductStock;

/**
 * The only place that changes stock quantities. Every change runs in a transaction on a locked row,
 * and a quantity can never go below zero. Sales, purchases, POS, transfers... all call this service.
 */
class StockService
{
    public function quantity(int $productId, int $warehouseId): float
    {
        return (float) ProductStock::where('product_id', $productId)->where('warehouse_id', $warehouseId)->value('quantity');
    }

    /** Add (positive) or remove (negative) stock. Returns the new quantity. */
    public function adjust(Product $product, int $warehouseId, float $delta): float
    {
        return DB::transaction(function () use ($product, $warehouseId, $delta) {
            $this->ensureRow($product, $warehouseId);

            $stock = ProductStock::where('product_id', $product->id)->where('warehouse_id', $warehouseId)->lockForUpdate()->firstOrFail();
            $new = round($stock->quantity + $delta, 2);

            if ($new < 0) {
                throw new InsufficientStockException($product->name, $stock->quantity, abs($delta));
            }

            $stock->update(['quantity' => $new]);

            return $new;
        });
    }

    /** Set an absolute quantity (manual stock count / opening balance). */
    public function set(Product $product, int $warehouseId, float $quantity): float
    {
        return DB::transaction(function () use ($product, $warehouseId, $quantity) {
            $this->ensureRow($product, $warehouseId);

            ProductStock::where('product_id', $product->id)->where('warehouse_id', $warehouseId)->lockForUpdate()->firstOrFail()
                ->update(['quantity' => round(max($quantity, 0), 2)]);

            return round(max($quantity, 0), 2);
        });
    }

    /** Move stock between two warehouses atomically (both sides change or neither does). */
    public function transfer(Product $product, int $fromWarehouseId, int $toWarehouseId, float $quantity): void
    {
        DB::transaction(function () use ($product, $fromWarehouseId, $toWarehouseId, $quantity) {
            $this->adjust($product, $fromWarehouseId, -$quantity);
            $this->adjust($product, $toWarehouseId, $quantity);
        });
    }

    private function ensureRow(Product $product, int $warehouseId): void
    {
        ProductStock::firstOrCreate(
            ['product_id' => $product->id, 'warehouse_id' => $warehouseId],
            ['quantity' => 0, 'created_by' => $product->created_by],
        );
    }
}
