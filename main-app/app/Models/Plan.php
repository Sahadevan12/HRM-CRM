<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    protected $fillable = [
        'name', 'description', 'monthly_price', 'yearly_price', 'max_users', 'free_plan',
        'trial', 'trial_days', 'modules', 'is_disable', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'modules' => 'array',
            'free_plan' => 'boolean',
            'trial' => 'boolean',
            'is_disable' => 'boolean',
            'monthly_price' => 'float',
            'yearly_price' => 'float',
        ];
    }

    /** Companies currently on this plan. */
    public function companies(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(User::class, 'active_plan');
    }

    /** Price for a billing duration ('month' | 'year'). */
    public function priceFor(string $duration): float
    {
        return $duration === 'year' ? $this->yearly_price : $this->monthly_price;
    }
}
