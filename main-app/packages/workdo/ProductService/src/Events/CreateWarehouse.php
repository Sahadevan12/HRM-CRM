<?php

namespace Workdo\ProductService\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Workdo\ProductService\Models\Warehouse;

/** Fired after a warehouse is created. Other modules can listen to it. */
class CreateWarehouse
{
    use Dispatchable;

    public function __construct(
        public Request $request,
        public Warehouse $warehouse,
    ) {
    }
}
