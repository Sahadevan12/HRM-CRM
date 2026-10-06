<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AddOn extends Model
{
    protected $fillable = ['module', 'name', 'package_name', 'monthly_price', 'yearly_price', 'is_enable', 'for_admin', 'priority'];

    protected function casts(): array
    {
        return ['is_enable' => 'boolean', 'for_admin' => 'boolean'];
    }
}
