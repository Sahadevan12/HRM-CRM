<?php

namespace Modules\ProductService\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Modules\ProductService\Models\StockTransfer;

/** Fired after a stock transfer was reversed and deleted. */
class DestroyStockTransfer
{
    use Dispatchable;

    public function __construct(public Request $request, public StockTransfer $transfer)
    {
    }
}
