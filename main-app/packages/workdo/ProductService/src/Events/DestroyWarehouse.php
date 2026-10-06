<?php

namespace Workdo\ProductService\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Workdo\ProductService\Models\Warehouse;

/** Fired after a warehouse is deleted. Other modules can listen to it. */
class DestroyWarehouse
{
    use Dispatchable;

    public function __construct(
        public Request $request,
        public Warehouse $warehouse,
    ) {
    }
}
