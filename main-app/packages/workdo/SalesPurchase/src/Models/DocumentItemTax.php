<?php

namespace Workdo\SalesPurchase\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentItemTax extends Model
{
    protected $table = 'document_item_taxes';

    protected $fillable = ['document_item_id', 'tax_id', 'name', 'rate', 'amount'];

    protected function casts(): array
    {
        return ['rate' => 'float', 'amount' => 'float'];
    }
}
