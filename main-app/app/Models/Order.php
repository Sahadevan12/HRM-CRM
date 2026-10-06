<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    protected $fillable = [
        'order_number', 'user_id', 'plan_id', 'plan_name', 'duration', 'price', 'discount',
        'final_price', 'coupon_code', 'payment_type', 'payment_status',
    ];

    protected function casts(): array
    {
        return ['price' => 'float', 'discount' => 'float', 'final_price' => 'float'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function bankTransfer(): HasOne
    {
        return $this->hasOne(BankTransferPayment::class);
    }

    public static function generateNumber(): string
    {
        do {
            $number = 'ORD-' . strtoupper(bin2hex(random_bytes(4)));
        } while (static::where('order_number', $number)->exists());

        return $number;
    }
}
