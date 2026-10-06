<?php

namespace Workdo\ProductService\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Workdo\ProductService\Models\StockTransfer;

/** Fired after stock was moved between two warehouses. */
class CreateStockTransfer
{
    use Dispatchable;

    public function __construct(public Request $request, public StockTransfer $transfer)
    {
    }
}
