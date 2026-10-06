<?php

namespace Workdo\ProductService\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockTransfer extends Model
{
    protected $table = 'stock_transfers';

    protected $fillable = [
        'product_id', 'from_warehouse_id', 'to_warehouse_id', 'quantity', 'transfer_date', 'notes',
        'creator_id', 'created_by',
    ];

    protected function casts(): array
    {
        return ['quantity' => 'float', 'transfer_date' => 'date:Y-m-d'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }
}
