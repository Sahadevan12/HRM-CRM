<?php

namespace Modules\ProductService\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Modules\ProductService\Models\Warehouse;

/** Fired after a warehouse is updated. Other modules can listen to it. */
class UpdateWarehouse
{
    use Dispatchable;

    public function __construct(
        public Request $request,
        public Warehouse $warehouse,
    ) {
    }
}
