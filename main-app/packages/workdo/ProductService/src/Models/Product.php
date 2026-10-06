<?php

namespace Workdo\ProductService\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    public const TYPES = ['product', 'service'];

    protected $table = 'products';

    protected $fillable = [
        'name',
        'sku',
        'type',
        'sale_price',
        'purchase_price',
        'description',
        'is_active',
        'category_id',
        'unit_id',
        'tax_ids',
        'creator_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'sale_price' => 'float',
            'purchase_price' => 'float',
            'is_active' => 'boolean',
            'tax_ids' => 'array',
        ];
    }

    public function isService(): bool
    {
        return $this->type === 'service';
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class, 'unit_id');
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(ProductStock::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }
}
