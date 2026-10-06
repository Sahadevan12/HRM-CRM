<?php

namespace Workdo\Pos\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Workdo\SalesPurchase\Models\Document;

class PosSale extends Model
{
    public const METHODS = ['cash', 'card', 'bank_transfer', 'credit'];

    protected $table = 'pos_sales';

    protected $fillable = ['document_id', 'cashier_id', 'payment_method', 'amount_paid', 'amount_tendered', 'change_due', 'created_by'];

    protected function casts(): array
    {
        return ['amount_paid' => 'float', 'amount_tendered' => 'float', 'change_due' => 'float'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }
}
