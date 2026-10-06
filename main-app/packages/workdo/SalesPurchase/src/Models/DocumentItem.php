<?php

namespace Workdo\SalesPurchase\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Workdo\ProductService\Models\Product;

class DocumentItem extends Model
{
    protected $table = 'document_items';

    protected $fillable = [
        'document_id', 'product_id', 'source_item_id', 'name', 'quantity', 'unit_price',
        'discount_amount', 'tax_amount', 'total_amount',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'unit_price' => 'float',
            'discount_amount' => 'float',
            'tax_amount' => 'float',
            'total_amount' => 'float',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function taxes(): HasMany
    {
        return $this->hasMany(DocumentItemTax::class);
    }
}
