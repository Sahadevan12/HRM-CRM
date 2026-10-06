<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Coupon extends Model
{
    protected $fillable = ['name', 'code', 'type', 'discount', 'usage_limit', 'expiry_date', 'is_active', 'created_by'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'expiry_date' => 'date:Y-m-d', 'discount' => 'float'];
    }

    public function usages(): HasMany
    {
        return $this->hasMany(UserCoupon::class);
    }

    /** Discount amount for a price (never more than the price itself). */
    public function discountFor(float $price): float
    {
        $discount = $this->type === 'percentage' ? $price * $this->discount / 100 : $this->discount;

        return round(min($discount, $price), 2);
    }
}
