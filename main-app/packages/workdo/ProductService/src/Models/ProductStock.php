<?php

namespace Workdo\ProductService\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductStock extends Model
{
    protected $table = 'product_stocks';

    protected $fillable = ['product_id', 'warehouse_id', 'quantity', 'created_by'];

    protected function casts(): array
    {
        return ['quantity' => 'float'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
