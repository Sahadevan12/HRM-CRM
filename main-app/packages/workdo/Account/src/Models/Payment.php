<?php

namespace Workdo\Account\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Workdo\SalesPurchase\Models\Document;

class Payment extends Model
{
    public const CUSTOMER = 'customer';
    public const VENDOR = 'vendor';

    protected $table = 'account_payments';

    protected $fillable = [
        'kind', 'party_id', 'document_id', 'account_id', 'payment_date', 'amount', 'reference', 'notes',
        'creator_id', 'created_by',
    ];

    protected function casts(): array
    {
        return ['payment_date' => 'date:Y-m-d', 'amount' => 'float'];
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(User::class, 'party_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_id');
    }
}
