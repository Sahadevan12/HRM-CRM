<?php

namespace Workdo\ProductService\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductTax extends Model
{
    protected $table = 'product_taxes';

    protected $fillable = [
        'name',
        'rate',
        'creator_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'float',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }
}
