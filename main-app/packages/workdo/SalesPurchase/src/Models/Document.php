<?php

namespace Workdo\SalesPurchase\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Workdo\ProductService\Models\Warehouse;

class Document extends Model
{
    protected $table = 'documents';

    protected $fillable = [
        'type', 'number', 'party_id', 'warehouse_id', 'parent_id', 'doc_date', 'due_date', 'status',
        'subtotal', 'discount_amount', 'tax_amount', 'total_amount', 'paid_amount',
        'notes', 'reason', 'posted_at', 'creator_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'doc_date' => 'date:Y-m-d',
            'due_date' => 'date:Y-m-d',
            'posted_at' => 'datetime',
            'subtotal' => 'float',
            'discount_amount' => 'float',
            'tax_amount' => 'float',
            'total_amount' => 'float',
            'paid_amount' => 'float',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(DocumentItem::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(User::class, 'party_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** Documents created from this one (returns of an invoice, invoice of a proposal). */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function balance(): float
    {
        return round($this->total_amount - $this->paid_amount, 2);
    }
}
